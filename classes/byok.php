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
use local_rtocompliance\local\byok\js;

/**
 * Bring your own AI: the plugin's AI requests answered on this site's own AI instead of at LMS Labs.
 *
 * When the admin has chosen the site's own AI for text in AI Grader Central Config (local_aiconfig), the
 * TAS / form-field drafts, survey analysis, student support auto-fill, validation methodology notes and the
 * compliance assistant run here, with the same instructions and checks as the LMS Labs service, and use no
 * LMS Labs credits. Free look-ups (training.gov.au, USI) keep using LMS Labs.
 *
 * @package    local_rtocompliance
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class byok {
    /** @var string The component the site's own AI is chosen for. */
    const COMPONENT = 'local_rtocompliance';

    /** @var int Seconds a text request may take. */
    const TEXT_TIMEOUT = 120;

    /** @var array The LMS Labs routes answered here, and the engine method for each. */
    const ROUTES = [
        '/api/rto/ai-suggest' => 'suggest',
        '/api/rto/ai-survey-analyze' => 'survey',
        '/api/rto/ai-support-autofill' => 'autofill',
        '/api/rto/ai-methodology-suggest' => 'methodology',
        '/api/assistant/ask' => 'ask',
    ];

    /** @var string[] The routes the browser may ask for through byok_ai.php. */
    const BROWSER_ROUTES = ['/api/rto/ai-suggest', '/api/rto/ai-support-autofill', '/api/rto/ai-methodology-suggest'];

    /** @var int The largest request body accepted, in bytes. */
    const MAX_BODY = 1048576;

    /**
     * Whether text AI runs on this site's own AI.
     *
     * @return bool
     */
    public static function uses(): bool {
        return class_exists('\\local_aiconfig\\byok\\ai') && \local_aiconfig\byok\ai::uses_own_ai(self::COMPONENT, 'text');
    }

    /**
     * The LMS Labs route of a URL, when it is one answered here.
     *
     * @param string $url
     * @return string|null
     */
    public static function route(string $url): ?string {
        $path = (string)parse_url($url, PHP_URL_PATH);
        foreach (array_keys(self::ROUTES) as $route) {
            if (substr($path, -strlen($route)) === $route) {
                return $route;
            }
        }
        return null;
    }

    /**
     * The answer to a request for an LMS Labs route, made on this site's own AI.
     *
     * @param string $route an LMS Labs route, such as /api/rto/ai-suggest
     * @param string $body the JSON request body the plugin would send to LMS Labs
     * @return array|null [HTTP status, JSON response body], or null when the route is not answered here
     */
    public static function answer(string $route, string $body): ?array {
        $method = self::ROUTES[$route] ?? null;
        if ($method === null || !self::uses()) {
            return null;
        }
        $invalid = [400, js::stringify((object)['success' => false, 'error' => 'Invalid request body.'])];
        if (strlen($body) > self::MAX_BODY) {
            return [413, js::stringify((object)['success' => false, 'error' => 'Request too large.'])];
        }
        try {
            $request = js::parse($body);
        } catch (\Throwable $e) {
            return $invalid;
        }
        if (is_array($request)) {
            $request = new \stdClass();
        } else if (!$request instanceof \stdClass) {
            return $invalid;
        }
        engine::$text = function (string $system, string $user, int $maxtokens, ?float $temperature, bool $json): string {
            return \local_aiconfig\byok\ai::text(self::COMPONENT, $system, $user, ['json' => $json,
                'maxtokens' => $maxtokens, 'temperature' => $temperature, 'timeout' => self::TEXT_TIMEOUT]);
        };
        [$status, $response] = engine::$method($request);
        return [$status, js::stringify($response)];
    }

    /**
     * The answer for rtocompliance_curl_post(): its [response, httpcode, error] shape.
     *
     * @param string $url
     * @param string $body
     * @return array|null
     */
    public static function post(string $url, string $body): ?array {
        $route = self::route($url);
        $answer = $route === null ? null : self::answer($route, $body);
        return $answer === null ? null : ['response' => $answer[1], 'httpcode' => $answer[0], 'error' => ''];
    }

    /**
     * Data attributes for a page's AI config element, so its scripts send AI requests to byok_ai.php.
     *
     * @return array attribute => value; empty when the site uses LMS Labs credits
     */
    public static function page_attributes(): array {
        if (!self::uses()) {
            return [];
        }
        return [
            'data-byok-url' => (new \moodle_url('/local/rtocompliance/byok_ai.php'))->out(false),
            'data-sesskey' => sesskey(),
            'data-byok-label' => get_string('byok_ownai', 'local_rtocompliance'),
        ];
    }

    /**
     * {@see self::page_attributes()} as HTML attribute text, for elements written as strings.
     *
     * @return string
     */
    public static function page_attributes_html(): string {
        $html = '';
        foreach (self::page_attributes() as $name => $value) {
            $html .= ' ' . $name . '="' . s($value) . '"';
        }
        return $html;
    }
}
