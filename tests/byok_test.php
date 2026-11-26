<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_rtocompliance;

use local_rtocompliance\local\byok\engine;
use local_rtocompliance\local\byok\prompts;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/rtocompliance/lib.php');

/**
 * Tests Bring your own AI: the AI features on the site's own AI, with the service's checks, at no charge.
 *
 * @package    local_rtocompliance
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_rtocompliance\byok
 * @covers     \local_rtocompliance\local\byok\engine
 */
#[\PHPUnit\Framework\Attributes\CoversClass(byok::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(engine::class)]
final class byok_test extends \advanced_testcase {
    /** @var array Request bodies the mocked OpenAI received. */
    private array $requests = [];

    /** @var array Answers the mocked OpenAI gives, in order. */
    private array $answers = [];

    /**
     * Licence the site for Bring your own AI on a test key, with text on OpenAI and no LMS Labs credentials.
     */
    protected function setUp(): void {
        parent::setUp();
        if (!class_exists('\local_aiconfig\byok\ai')) {
            $this->markTestSkipped('AI Grader Central Config with Bring your own AI is not installed.');
        }
        $this->resetAfterTest();
        $this->setAdminUser();
        $pair = sodium_crypto_sign_keypair();
        set_config('testpublickey', base64_encode(sodium_crypto_sign_publickey($pair)), 'local_aiconfig');
        $payload = json_encode(['v' => 1, 'id' => 'lic_t', 'site' => \local_aiconfig\byok\licence::site_host(),
            'plan' => 'pro', 'byok' => true, 'products' => ['*'], 'sub' => 'sub_t', 'exp' => time() + DAYSECS]);
        $b64 = fn(string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        set_config('licencekey', $b64($payload) . '.' .
            $b64(sodium_crypto_sign_detached($payload, sodium_crypto_sign_secretkey($pair))), 'local_aiconfig');
        set_config('src_text', 'ownkey', 'local_aiconfig');
        set_config('vendor_text', 'openai', 'local_aiconfig');
        set_config('key_openai', 'sk-test', 'local_aiconfig');
        foreach (['siteid', 'apikey'] as $name) {
            set_config($name, '', 'local_aiconfig');
            set_config($name, '', 'local_rtocompliance');
        }
        // An address that cannot be reached: LMS Labs must never be called.
        set_config('apiurl', 'https://lmslabs.invalid', 'local_rtocompliance');
        $this->requests = [];
        $this->answers = [];
        $GLOBALS['local_aiconfig_byok_mock'] = function ($url, $headers, $body, $vendor, $raw = false) {
            if (strpos($url, 'lmslabs.invalid') !== false) {
                throw new \coding_exception('LMS Labs was called: ' . $url);
            }
            $this->requests[] = $body;
            $answer = array_shift($this->answers);
            if ($answer === null) {
                throw new \moodle_exception('byok_callfailed', 'local_aiconfig', '', (object)['vendor' => $vendor,
                    'detail' => 'Overloaded']);
            }
            return ['choices' => [['message' => ['content' => $answer], 'finish_reason' => 'stop']]];
        };
    }

    protected function tearDown(): void {
        unset($GLOBALS['local_aiconfig_byok_mock']);
        engine::$text = null;
        parent::tearDown();
    }

    /**
     * The AI request's system and user text.
     *
     * @param int $n
     * @return array [system, user]
     */
    private function prompt(int $n = 0): array {
        $messages = $this->requests[$n]['messages'];
        return [$messages[0]['content'], $messages[1]['content'][0]['text']];
    }

    public function test_three_options_on_own_ai(): void {
        $this->answers = [json_encode(['options' => ['First option.', '  ', 7, 'Second option.']])];
        $body = json_encode(['apiKey' => '', 'field' => 'targetcohort', 'qualCode' => 'BSB50120',
            'qualName' => 'Diploma of Business', 'count' => 3, 'keyword' => 'regional',
            'context' => ['scopedetails' => '  Scope text  ', 'targetcohort' => 'excluded']]);
        $result = byok::post('https://lmslabs.invalid/api/rto/ai-suggest', $body);
        $this->assertSame(200, $result['httpcode']);
        $this->assertSame('', $result['error']);
        $data = json_decode($result['response'], true);
        $this->assertSame(['First option.', 'Second option.'], $data['suggestions']);
        $this->assertSame(0, $data['creditsUsed']);
        [$system, $user] = $this->prompt();
        $this->assertStringContainsString(prompts::FIELDS['targetcohort'][1], $system);
        $this->assertStringContainsString('- Generate 3 MEANINGFULLY DIFFERENT options', $system);
        $this->assertStringContainsString("Qualification Code: BSB50120\nQualification Name: Diploma of Business\n" .
            prompts::FIELDS['scopedetails'][0] . ': Scope text', $user);
        $this->assertStringNotContainsString('excluded', $user);
        $this->assertStringContainsString("KEYWORD/FOCUS: regional\n", $user);
        $this->assertSame(['type' => 'json_object'], $this->requests[0]['response_format']);
        $this->assertSame(0.75, $this->requests[0]['temperature']);
        $this->assertSame(2400, $this->requests[0]['max_completion_tokens']);
    }

    public function test_one_draft_and_errors(): void {
        // One draft: the answer, trimmed, with the field's own token limit.
        $this->answers = ["  The resolution.\n"];
        [$status, $json] = byok::answer('/api/rto/ai-suggest', json_encode(['field' => 'complaint_resolution', 'count' => 1]));
        $this->assertSame(200, $status);
        $this->assertSame(['The resolution.'], json_decode($json)->suggestions);
        $this->assertArrayNotHasKey('response_format', $this->requests[0]);
        $this->assertSame(prompts::FIELDS['complaint_resolution'][3] ?? 1200, $this->requests[0]['max_completion_tokens']);

        // An unknown field is refused before any AI call.
        [$status, $json] = byok::answer('/api/rto/ai-suggest', json_encode(['field' => 'constructor']));
        $this->assertSame([400, 'Unknown field: constructor'], [$status, json_decode($json)->error]);
        $this->assertCount(1, $this->requests);

        // A failed AI call is a service error with the provider's message.
        [$status, $json] = byok::answer('/api/rto/ai-suggest', json_encode(['field' => 'targetcohort']));
        $this->assertSame(503, $status);
        $this->assertStringStartsWith('AI service error: ', json_decode($json)->error);
        $this->assertStringContainsString('Overloaded', json_decode($json)->error);

        // Bodies that are not a JSON object, or are too large, are refused.
        $this->assertSame(400, byok::answer('/api/rto/ai-suggest', '{bad')[0]);
        $this->assertSame(400, byok::answer('/api/rto/ai-suggest', '"text"')[0]);
        $this->assertSame(413, byok::answer('/api/rto/ai-suggest', str_repeat(' ', byok::MAX_BODY + 1))[0]);
        $this->assertCount(2, $this->requests);

        // Routes not ported stay with LMS Labs.
        $this->assertNull(byok::post('https://lms-labs.com/api/tga/qualification/BSB50120', '{}'));
    }

    public function test_survey_analysis_on_own_ai(): void {
        $this->assertTrue((new \local_rtocompliance\ai\survey_analyzer())->is_configured());
        $this->answers = ['{"sentiment":"positive"}'];
        $body = json_encode(['surveyType' => 'learner', 'responsesText' => "Q: Overall?\nA: Great\n"]);
        [$status, $json] = byok::answer('/api/rto/ai-survey-analyze', $body);
        $this->assertSame(200, $status);
        $this->assertSame('{"sentiment":"positive"}', json_decode($json)->analysisJson);
        [$system, $user] = $this->prompt();
        $this->assertStringContainsString('Analyse the following Learner Engagement Survey responses', $user);
        $this->assertStringContainsString("SURVEY RESPONSES:\nQ: Overall?\nA: Great\n\n\n", $user);
        $this->assertSame(0.3, $this->requests[0]['temperature']);
        $this->assertSame(400, byok::answer('/api/rto/ai-survey-analyze', json_encode(['surveyType' => 'learner']))[0]);
    }

    public function test_support_autofill_on_own_ai(): void {
        $fields = ['lln' => 'a', 'adjustments' => 'b', 'referrals' => 'c', 'interventions' => 'd', 'diversity' => 'e',
            'wellbeing' => 'f'];
        $fence = str_repeat(chr(96), 3);
        $this->answers = [$fence . "json\n" . json_encode($fields) . "\n" . $fence];
        $body = json_encode(['studentName' => ' Mia Tran ', 'llnLevel' => '', 'riskLevel' => 'High',
            'qualName' => 'Certificate III in Hospitality', 'qualCode' => 'SIT30622']);
        [$status, $json] = byok::answer('/api/rto/ai-support-autofill', $body);
        $this->assertSame(200, $status);
        $this->assertEquals((object)$fields, json_decode($json)->fields);
        [, $user] = $this->prompt();
        $this->assertStringContainsString("Student name: Mia Tran\nLLN level (ACSF): ACSF 3\nRisk level: High\n" .
            "Qualification: Certificate III in Hospitality (SIT30622)\n", $user);
        $this->assertStringContainsString('this high-risk student — be specific about escalation steps', $user);

        // An answer with a blank field is refused, as the service does.
        $fields['wellbeing'] = '';
        $this->answers = [json_encode($fields)];
        [$status, $json] = byok::answer('/api/rto/ai-support-autofill', $body);
        $this->assertSame([500, 'AI returned an unexpected format. Please try again.'], [$status, json_decode($json)->error]);
        $this->assertSame(400, byok::answer('/api/rto/ai-support-autofill', '{}')[0]);
    }

    public function test_methodology_notes_on_own_ai(): void {
        $this->answers = ["  Notes text.  "];
        $body = json_encode(['methods' => ['tool_review', 'custom'], 'productname' => 'Diploma of Business', 'productcode' => '']);
        [$status, $json] = byok::answer('/api/rto/ai-methodology-suggest', $body);
        $this->assertSame(200, $status);
        $this->assertSame('Notes text.', json_decode($json)->text);
        [$system, $user] = $this->prompt();
        $this->assertSame(prompts::METHODOLOGY, $system);
        $this->assertStringStartsWith("Qualification: Diploma of Business\nSelected validation methodologies: " .
            prompts::METHOD_LABELS['tool_review'] . "; custom\n\n", $user);
        $this->assertSame(300, $this->requests[0]['max_completion_tokens']);
        $this->assertSame(400, byok::answer('/api/rto/ai-methodology-suggest', json_encode(['methods' => []]))[0]);
    }

    public function test_assistant_on_own_ai(): void {
        $this->assertTrue(local_rtocompliance_assistant_creds()['ready']);
        $this->answers = ['Open **Student Records**.'];
        $result = local_rtocompliance_assistant_ask([
            ['role' => 'user', 'content' => 'Where are students?'],
            ['role' => 'assistant', 'content' => 'Which kind?'],
            ['role' => 'user', 'content' => 'Enrolled ones'],
        ], 'students');
        $this->assertSame(['ok' => true, 'reply' => 'Open **Student Records**.', 'credits' => null, 'mode' => 'ownai',
            'error' => ''], $result);
        [$system, $user] = $this->prompt();
        $this->assertStringStartsWith(prompts::AUDITOR_PERSONA . "\n\n", $system);
        $this->assertStringContainsString('The user is on page: students.', $system);
        $this->assertSame("Conversation so far (oldest first):\n\nUser:\nWhere are students?\n\nAssistant:\nWhich kind?\n\n" .
            "Latest message (User):\nEnrolled ones", $user);
        $this->assertArrayNotHasKey('temperature', $this->requests[0]);
        $this->assertSame(2048, $this->requests[0]['max_completion_tokens']);

        // A failed call is reported, not sent on to LMS Labs.
        $result = local_rtocompliance_assistant_ask([['role' => 'user', 'content' => 'Again?']], 'students');
        $this->assertFalse($result['ok']);
        $this->assertSame('ownai', $result['mode']);
        $this->assertStringContainsString('Overloaded', $result['error']);
    }

    public function test_pages_and_switching_off(): void {
        $attributes = byok::page_attributes();
        $this->assertStringEndsWith('/local/rtocompliance/byok_ai.php', $attributes['data-byok-url']);
        $this->assertSame(sesskey(), $attributes['data-sesskey']);
        $this->assertStringContainsString('data-byok-url="', byok::page_attributes_html());

        // With LMS Labs credits chosen, nothing is answered here.
        set_config('src_text', 'credits', 'local_aiconfig');
        $this->assertFalse(byok::uses());
        $this->assertNull(byok::post('https://lms-labs.com/api/rto/ai-suggest', '{"field":"targetcohort"}'));
        $this->assertSame([], byok::page_attributes());
        $this->assertSame('', byok::page_attributes_html());
        $this->assertFalse(local_rtocompliance_assistant_creds()['ready']);
        $this->assertSame([], $this->requests);
    }
}
