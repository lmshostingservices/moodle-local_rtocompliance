<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_rtocompliance\local\byok;

/**
 * The LMS Labs RTO Compliance AI routes, ported to run on the site's own AI.
 *
 * POST /api/rto/ai-suggest, /api/rto/ai-survey-analyze, /api/rto/ai-support-autofill and /api/rto/ai-methodology-suggest
 * (server/routes.ts) and POST /api/assistant/ask (assistantRoute.ts): the instructions the service builds from the
 * request, the settings it asks the AI with, and its checks and shaping of the AI's answer. Only the calls to the AI go
 * to the site's own AI, through the hook below. The behaviour was compared with the service's code on generated requests
 * and AI answers.
 *
 * Each method takes the request body the plugin sends to LMS Labs (decoded with JavaScript's rules) and answers
 * [HTTP status, response body] as the service does, with no credits used. The credential and credit checks are left out:
 * nothing is charged.
 *
 * @package    local_rtocompliance
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class engine {
    /**
     * @var callable|null fn(string $system, string $user, int $maxtokens, ?float $temperature, bool $json): string
     */
    public static $text = null;

    /** @var string[] Fields of a per-student support record (ai-support-autofill). */
    const SUPPORT_FIELDS = ['lln', 'adjustments', 'referrals', 'interventions', 'diversity', 'wellbeing'];

    /** @var string[] Names the service cannot look up as fields (JavaScript object prototype members). */
    const PROTOTYPE = ['constructor', '__proto__', 'hasOwnProperty', 'isPrototypeOf', 'propertyIsEnumerable', 'toString',
        'valueOf', 'toLocaleString', '__defineGetter__', '__defineSetter__', '__lookupGetter__', '__lookupSetter__'];

    /**
     * POST /api/rto/ai-suggest: one text, or up to three options, for a compliance form field.
     *
     * @param \stdClass $body
     * @return array [status, response]
     */
    public static function suggest(\stdClass $body): array {
        $field = self::prop($body, 'field');
        $key = $field === self::$undefined ? 'undefined' : js::str($field);
        $config = is_string($key) && !in_array($key, self::PROTOTYPE, true) ? (prompts::FIELDS[$key] ?? null) : null;
        if ($config === null) {
            return [400, (object)['success' => false, 'error' => 'Unknown field: ' . self::template($field)]];
        }
        [$label, $guide, $hint, $maxtokens] = $config;
        $lines = [];
        $titles = ['qualCode' => 'Qualification Code', 'qualName' => 'Qualification Name', 'deliveryMode' => 'Delivery Mode'];
        foreach ($titles as $name => $title) {
            $value = self::prop($body, $name);
            if ($value !== self::$undefined && js::truthy($value)) {
                $lines[] = "{$title}: " . self::template($value);
            }
        }
        $context = self::prop($body, 'context');
        if ($context instanceof \stdClass || is_array($context)) {
            foreach (prompts::FIELDS as $f => [$flabel]) {
                if ($f === $field) {
                    continue;
                }
                $value = $context instanceof \stdClass ? self::prop($context, $f) : self::$undefined;
                if ($value === self::$undefined || !js::truthy($value)) {
                    continue;
                }
                $text = js::trim(self::template($value));
                if ($text !== '') {
                    $lines[] = "{$flabel}: " . js::slice($text, 0, 400);
                }
            }
        }
        $contextstr = implode("\n", $lines);
        $count = self::prop($body, 'count');
        $count = $count === self::$undefined ? 1 : self::number($count);
        $count = (is_nan($count) || $count == 0) ? 1 : $count;
        $n = min($count, 3);
        $many = $n > 1;
        $keyword = self::prop($body, 'keyword');
        $haskeyword = $keyword !== self::$undefined && js::truthy($keyword);
        $kw = $haskeyword ? self::template($keyword) : '';
        $ns = js::number($n);
        $system = 'You are an expert in Australian Vocational Education and Training (VET) and RTO compliance. ' .
            'You write professional, ASQA-compliant content for Training and Assessment Strategies (TAS) ' .
            'and other RTO compliance documents. Your writing reflects best practice per the ASQA TAS Practice Guide ' .
            "(2024 edition) and the ASQA Standards for RTOs 2025.\n\n" .
            "ASQA COMPLIANCE CONTEXT FOR THIS FIELD:\n{$guide}\n\n" .
            "WRITING RULES:\n" .
            "- Professional, clear Australian English\n" .
            "- Specific and practical — avoid generic boilerplate\n" .
            "- Reference relevant ASQA standards or clauses where appropriate\n" .
            "- Content must reflect the actual qualification and delivery context provided\n" .
            ($haskeyword ? "- Tailor ALL content specifically for this keyword/context: \"{$kw}\"\n" : '') .
            ($many ? "- Generate {$ns} MEANINGFULLY DIFFERENT options — different angle, emphasis, or approach for each\n" : '');
        $user = $many
            ? "Generate {$ns} different, meaningfully distinct options for the \"{$label}\" field " .
                "in a Training and Assessment Strategy (TAS) document.\n\n" .
                "TAS CONTEXT (previously completed fields):\n{$contextstr}\n\n" .
                "FIELD: {$label}\n" .
                "WRITING GUIDANCE: {$hint}\n" .
                ($haskeyword ? "KEYWORD/FOCUS: {$kw}\n" : '') .
                "\nReturn a JSON object ONLY (no markdown, no code blocks, no explanation):\n" .
                '{"options":["First option text here...","Second option text here...","Third option text here..."]}'
            : "Write professional content for the \"{$label}\" field in a Training and Assessment Strategy (TAS) document.\n\n" .
                "TAS CONTEXT (previously completed fields):\n{$contextstr}\n\n" .
                "FIELD: {$label}\n" .
                "WRITING GUIDANCE: {$hint}\n" .
                ($haskeyword ? "KEYWORD/FOCUS: {$kw}\n" : '') .
                "\nReturn ONLY the content text. No JSON, no markdown, no preamble or explanation.";
        try {
            $answer = (string)(self::$text)($system, $user, $many ? 2400 : ($maxtokens ?? 1200), 0.75, $many);
        } catch (\Throwable $e) {
            return [503, (object)['success' => false, 'error' => 'AI service error: ' . $e->getMessage()]];
        }
        if ($many) {
            $suggestions = [];
            try {
                $options = js::get(js::parse($answer), 'options');
                foreach (is_array($options) ? $options : [] as $s) {
                    if (is_string($s) && js::trim($s) !== '') {
                        $suggestions[] = $s;
                    }
                }
            } catch (\Throwable $e) {
                $suggestions = [];
            }
            if (!$suggestions) {
                // The service's fallback: paragraphs of more than 20 characters.
                foreach (preg_split('/\n{2,}/', $answer) as $part) {
                    $part = js::trim($part);
                    if (js::len($part) > 20) {
                        $suggestions[] = $part;
                    }
                }
                $suggestions = array_slice($suggestions, 0, (int)floor($n));
            }
        } else {
            $suggestions = [js::trim($answer)];
        }
        return [200, (object)['success' => true, 'suggestions' => $suggestions, 'creditsUsed' => 0, 'creditsRemaining' => -1]];
    }

    /**
     * POST /api/rto/ai-survey-analyze: an analysis of Quality Indicator survey responses, as the AI's JSON text.
     *
     * @param \stdClass $body
     * @return array [status, response]
     */
    public static function survey(\stdClass $body): array {
        $type = self::prop($body, 'surveyType');
        $responses = self::prop($body, 'responsesText');
        if ($type === self::$undefined || !js::truthy($type) || $responses === self::$undefined || !js::truthy($responses)) {
            return [400, (object)['success' => false, 'error' => 'surveyType and responsesText are required.']];
        }
        $label = $type === 'learner' ? 'Learner Engagement Survey' : 'Employer Satisfaction Survey';
        $system = 'You are an expert RTO compliance analyst specialising in Australian Quality Indicator surveys. ' .
            'Always respond with valid JSON only.';
        $user = "You are an expert RTO compliance analyst specialising in Australian Quality Indicator surveys.\n" .
            "Analyse the following {$label} responses and provide a comprehensive analysis.\n\n" .
            "SURVEY RESPONSES:\n" . self::template($responses) . "\n\n" .
            "Provide your analysis in the following JSON format:\n" .
            "{\n" .
            "    \"sentiment\": \"positive|neutral|negative\",\n" .
            "    \"sentiment_score\": <number between -1.0 and 1.0>,\n" .
            "    \"satisfaction_index\": <number between 0 and 100>,\n" .
            "    \"themes\": [\"theme1\", \"theme2\"],\n" .
            "    \"strengths\": [\"strength1\", \"strength2\"],\n" .
            "    \"improvements\": [\"area1\", \"area2\"],\n" .
            "    \"recommendations\": [\"recommendation1\", \"recommendation2\"],\n" .
            "    \"word_frequencies\": {\"word1\": count},\n" .
            "    \"trend_summary\": \"Brief summary of overall trends\",\n" .
            "    \"full_report\": \"Detailed analysis report (2-3 paragraphs)\"\n" .
            "}\n\n" .
            "Focus on actionable insights that help the RTO improve training quality and compliance.\n" .
            'Return ONLY valid JSON, no other text.';
        try {
            $answer = (string)(self::$text)($system, $user, 2000, 0.3, false);
        } catch (\Throwable $e) {
            return [500, (object)['success' => false, 'error' => 'AI analysis failed: ' . $e->getMessage()]];
        }
        return [200, (object)['success' => true, 'analysisJson' => $answer, 'creditsUsed' => 0, 'creditsRemaining' => -1]];
    }

    /**
     * POST /api/rto/ai-support-autofill: the six fields of a per-student support record.
     *
     * @param \stdClass $body
     * @return array [status, response]
     */
    public static function autofill(\stdClass $body): array {
        $name = self::prop($body, 'studentName');
        if ($name === self::$undefined || !js::truthy($name)) {
            return [400, (object)['success' => false, 'error' => 'studentName is required.']];
        }
        $or = function (string $key, string $default) use ($body): string {
            $value = self::prop($body, $key);
            return js::trim($value !== self::$undefined && js::truthy($value) ? self::template($value) : $default);
        };
        $sname = js::trim(self::template($name));
        $lln = $or('llnLevel', 'ACSF 3');
        $risk = $or('riskLevel', 'Low');
        $qualname = self::prop($body, 'qualName');
        $qualcode = self::prop($body, 'qualCode');
        $qual = 'the qualification';
        if ($qualname !== self::$undefined && js::truthy($qualname)) {
            $qual = self::template($qualname) . ($qualcode !== self::$undefined && js::truthy($qualcode)
                ? ' (' . self::template($qualcode) . ')' : '');
        }
        $system = 'You are an expert RTO (Registered Training Organisation) compliance officer in Australia. ' .
            'You write professional, ASQA-compliant per-student support records that form part of the ' .
            'evidence trail under Standards 2.3, 2.4, 2.5, and 2.6 of the Standards for RTOs 2025. ' .
            'Write in third person, using the student\'s name. Be specific and practical — avoid generic boilerplate. ' .
            'Use professional Australian English. Do NOT use markdown formatting or bullet points — write in plain prose only.';
        $focus = $risk === 'High' ? 'this high-risk student — be specific about escalation steps'
            : ($risk === 'Medium' ? 'this moderate-risk student — describe monitoring approach'
            : 'this low-risk student — note standard monitoring in place');
        $user = "Generate 6 per-student support record fields for the following student:\n\n" .
            "Student name: {$sname}\n" .
            "LLN level (ACSF): {$lln}\n" .
            "Risk level: {$risk}\n" .
            "Qualification: {$qual}\n\n" .
            "Write each field as 2-3 sentences of plain professional prose (no markdown, no bullet points, no headings).\n" .
            "Tailor ALL content to the student's actual LLN level and risk level.\n\n" .
            "Return ONLY a JSON object with exactly these keys:\n" .
            "{\n" .
            "  \"lln\": \"...\",\n" .
            "  \"adjustments\": \"...\",\n" .
            "  \"referrals\": \"...\",\n" .
            "  \"interventions\": \"...\",\n" .
            "  \"diversity\": \"...\",\n" .
            "  \"wellbeing\": \"...\"\n" .
            "}\n\n" .
            "FIELD GUIDANCE:\n" .
            '- lln: LLN observations and support provided (Standard 2.3). Reference the ACSF level and specific support ' .
            "strategies used.\n" .
            '- adjustments: Reasonable adjustments made (Standard 2.4). Name specific adjustments applied (extended time, ' .
            "alternative format, assistive technology, etc.) or confirm none were required.\n" .
            '- referrals: Support service referrals made. Name actual services (internal academic support, LLN specialist, ' .
            "wellbeing services, external counselling, etc.).\n" .
            "- interventions: Intervention strategies for {$focus}.\n" .
            '- diversity: Diversity and inclusion considerations applied (Standard 2.5). Reference cultural safety, CALD ' .
            "considerations, and accessibility practices.\n" .
            '- wellbeing: Wellbeing notes (Standard 2.6). Reference specific wellbeing supports offered and any welfare ' .
            "referrals made.\n" .
            "\nReturn ONLY the JSON object — no other text, no code blocks, no explanation.";
        try {
            $answer = (string)(self::$text)($system, $user, 1200, 0.65, false);
        } catch (\Throwable $e) {
            return [500, (object)['success' => false, 'error' => 'AI autofill failed: ' . $e->getMessage()]];
        }
        $ws = '[' . js::WS . ']';
        // A code fence (three backticks) around the answer is removed.
        $cleaned = preg_replace('/^\x60{3}(?:json)?' . $ws . '*/iu', '', $answer);
        $cleaned = js::trim(preg_replace('/\x60{3}' . $ws . '*$/Du', '', $cleaned));
        $unexpected = [500, (object)['success' => false, 'error' => 'AI returned an unexpected format. Please try again.']];
        try {
            $fields = js::parse($cleaned);
        } catch (\Throwable $e) {
            return $unexpected;
        }
        if ($fields === null) {
            return $unexpected;
        }
        foreach (self::SUPPORT_FIELDS as $k) {
            if (!js::truthy(js::get($fields, $k))) {
                return $unexpected;
            }
        }
        return [200, (object)['success' => true, 'fields' => $fields, 'creditsUsed' => 0, 'creditsRemaining' => -1]];
    }

    /**
     * POST /api/rto/ai-methodology-suggest: "Additional Methodology Notes" for a validation event.
     *
     * @param \stdClass $body
     * @return array [status, response]
     */
    public static function methodology(\stdClass $body): array {
        $methods = self::prop($body, 'methods');
        if (!is_array($methods) || !$methods) {
            return [400, (object)['success' => false, 'error' => 'No methodology codes provided']];
        }
        $labels = [];
        foreach ($methods as $m) {
            $key = js::str($m);
            $label = !in_array($key, self::PROTOTYPE, true) ? (prompts::METHOD_LABELS[$key] ?? null) : null;
            $labels[] = $label ?? ($m === null ? '' : js::str($m));
        }
        $refs = [];
        foreach (['productname', 'productcode'] as $name) {
            $value = self::prop($body, $name);
            if ($value !== self::$undefined && js::truthy($value)) {
                $refs[] = $value === null ? '' : js::str($value);
            }
        }
        $qualref = implode(' — ', $refs);
        $qualref = $qualref !== '' ? $qualref : 'this qualification';
        $user = "Qualification: {$qualref}\nSelected validation methodologies: " . implode('; ', $labels) . "\n\n" .
            'Write 2–4 sentences of Additional Methodology Notes for this validation event. Describe how these ' .
            'methodologies were applied to validate the assessment tools and practices, what aspects they aimed to verify ' .
            'against the training package requirements, and how the findings will inform continuous improvement actions.';
        try {
            $text = js::trim((string)(self::$text)(prompts::METHODOLOGY, $user, 300, 0.6, false));
        } catch (\Throwable $e) {
            return [500, (object)['success' => false, 'error' => 'AI suggest failed: ' . $e->getMessage()]];
        }
        if ($text === '') {
            return [500, (object)['success' => false, 'error' => 'Empty AI response']];
        }
        return [200, (object)['success' => true, 'text' => $text, 'creditsUsed' => 0, 'creditsRemaining' => -1]];
    }

    /**
     * POST /api/assistant/ask: the compliance assistant's answer to the conversation.
     *
     * The service first looks for ASQA and legislative passages in its own document library; that library lives at
     * LMS Labs, so on the site's own AI the assistant answers as the service does when the library has nothing: from
     * the plugin's knowledge base. The conversation is sent as one request (the site's own AI takes one message).
     *
     * @param \stdClass $body
     * @return array [status, response]
     */
    public static function ask(\stdClass $body): array {
        $value = fn(string $key, string $default) => ($v = self::prop($body, $key)) !== self::$undefined && js::truthy($v)
            ? self::template($v) : $default;
        $kb = $value('knowledge_base', '');
        $page = $value('page', 'unknown');
        $incoming = self::prop($body, 'messages');
        $messages = [];
        foreach (is_array($incoming) ? $incoming : [] as $m) {
            $content = js::get($m, 'content');
            if (!js::truthy($m) || !js::truthy($content)) {
                continue;
            }
            $role = js::get($m, 'role') === 'assistant' ? 'assistant' : 'user';
            $messages[] = [$role, js::slice(self::template($content), 0, 4000)];
        }
        $messages = array_slice($messages, -10);
        if (!$messages) {
            return [400, (object)['error' => 'Empty question.']];
        }
        $system = prompts::AUDITOR_PERSONA . "\n\n" . ($kb !== '' ? "## Plugin Knowledge Base\n{$kb}\n\n" : '') .
            "The user is on page: {$page}. " .
            'Answer as an expert on THIS plugin: precise, practical, name the exact page/button. ' .
            'Never invent features not in the knowledge base.';
        try {
            $reply = js::trim((string)(self::$text)($system, self::conversation($messages), 2048, null, false));
        } catch (\Throwable $e) {
            return [500, (object)['error' => $e->getMessage()]];
        }
        if ($reply === '') {
            return [502, (object)['error' => 'No answer generated.']];
        }
        return [200, (object)['reply' => $reply, 'sources' => [], 'creditsRemaining' => -1]];
    }

    /**
     * A conversation as one request: the earlier turns, labelled, then the latest message.
     *
     * @param array $messages [role, content] pairs, oldest first
     * @return string
     */
    public static function conversation(array $messages): string {
        $last = array_pop($messages);
        if (!$messages && $last[0] === 'user') {
            return $last[1];
        }
        $turns = array_map(fn($m) => ($m[0] === 'assistant' ? 'Assistant' : 'User') . ":\n" . $m[1], $messages);
        return ($turns ? "Conversation so far (oldest first):\n\n" . implode("\n\n", $turns) . "\n\n" : '') .
            'Latest message (' . ($last[0] === 'assistant' ? 'Assistant' : 'User') . "):\n" . $last[1];
    }

    /** @var \stdClass|null A missing property (JavaScript's undefined), apart from null. */
    private static $undefined = null;

    /**
     * A property of a request body: the value, or {@see self::$undefined} when it is missing.
     *
     * @param \stdClass $o
     * @param string $key
     * @return mixed
     */
    private static function prop(\stdClass $o, string $key) {
        self::$undefined = self::$undefined ?? new \stdClass();
        return property_exists($o, $key) ? $o->$key : self::$undefined;
    }

    /**
     * A value in a JavaScript template literal (`${value}`): String(value), with null as "null".
     *
     * @param mixed $value
     * @return string
     */
    private static function template($value): string {
        if ($value === self::$undefined) {
            return 'undefined';
        }
        return $value === null ? 'null' : js::str($value);
    }

    /**
     * JavaScript's Number(value).
     *
     * @param mixed $value
     * @return float
     */
    private static function number($value): float {
        if ($value === null || is_bool($value)) {
            return (float)(bool)$value;
        }
        if (is_int($value) || is_float($value)) {
            return (float)$value;
        }
        if ($value instanceof \stdClass) {
            return NAN;
        }
        $s = js::trim(is_array($value) ? js::str($value) : (string)$value);
        if ($s === '') {
            return 0.0;
        }
        if (preg_match('/^[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?$/D', $s)) {
            return (float)$s;
        }
        if (preg_match('/^0([xob])([0-9a-f]+)$/Di', $s, $m)) {
            $base = ['x' => 16, 'o' => 8, 'b' => 2][strtolower($m[1])];
            $digits = strtolower($m[2]);
            $valid = ['16' => '/^[0-9a-f]+$/', '8' => '/^[0-7]+$/', '2' => '/^[01]+$/'][(string)$base];
            return preg_match($valid, $digits) ? (float)base_convert($digits, $base, 10) : NAN;
        }
        if (preg_match('/^([+-]?)Infinity$/D', $s, $m)) {
            return $m[1] === '-' ? -INF : INF;
        }
        return NAN;
    }
}
