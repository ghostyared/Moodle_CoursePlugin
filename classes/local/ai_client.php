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

namespace local_ai_yared_course\local;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/filelib.php');

/**
 * Client for the supported AI providers (Gemini, OpenAI, Grok and OpenAI-compatible servers).
 *
 * The client can hold several provider profiles. The first one is the main provider and the rest
 * are fallbacks: if a profile fails because of a quota limit, a connection problem, an invalid key
 * or a model that no longer exists, the next profile is tried automatically.
 *
 * @package    local_ai_yared_course
 * @copyright  2026 GhostYared Corp
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ai_client {

    /** @var array Default base URL and model per provider. */
    public const DEFAULTS = [
        'gemini' => ['baseurl' => 'https://generativelanguage.googleapis.com/v1beta', 'model' => 'gemini-3.5-flash-lite'],
        'openai' => ['baseurl' => 'https://api.openai.com/v1', 'model' => 'gpt-4o-mini'],
        'grok' => ['baseurl' => 'https://api.x.ai/v1', 'model' => 'grok-3-mini'],
        'deepseek' => ['baseurl' => 'https://api.deepseek.com/v1', 'model' => 'deepseek-chat'],
        'qwen' => ['baseurl' => 'https://dashscope-intl.aliyuncs.com/compatible-mode/v1', 'model' => 'qwen-plus'],
        'kimi' => ['baseurl' => 'https://api.moonshot.ai/v1', 'model' => 'kimi-k2-turbo-preview'],
        'mistral' => ['baseurl' => 'https://api.mistral.ai/v1', 'model' => 'mistral-small-latest'],
        'groq' => ['baseurl' => 'https://api.groq.com/openai/v1', 'model' => 'llama-3.3-70b-versatile'],
        'openrouter' => ['baseurl' => 'https://openrouter.ai/api/v1', 'model' => 'openai/gpt-4o-mini'],
        'zhipu' => ['baseurl' => 'https://open.bigmodel.cn/api/paas/v4', 'model' => 'glm-4-flash'],
        'custom' => ['baseurl' => '', 'model' => ''],
    ];

    /** @var int Number of provider slots: the main one plus five backups. */
    public const MAX_SLOTS = 6;

    /** @var int[] Output token limits that some providers enforce (the request fails above them). */
    public const PROVIDER_MAX_TOKENS = ['deepseek' => 8192, 'groq' => 8192, 'mistral' => 8192];

    /** @var int Maximum number of output tokens requested from the provider. */
    public const MAX_OUTPUT_TOKENS = 32768;

    /** @var array[] Provider profiles, in fallback order. */
    protected $profiles = [];

    /** @var int Index of the last profile that worked, or -1 if none. */
    protected $activeprofile = -1;

    /** @var string Label of a provider profile to try last (used to ask "another model" for the same task). */
    protected $avoidlabel = '';

    /** @var int HTTP status of the last request: -1 none, 0 connection error. */
    protected $lasthttp = -1;

    /** @var int cURL error number of the last request (28 = timeout). */
    protected $lasterrno = 0;

    /** @var int Input tokens used since the last clear_fallback_messages() (start of a topic). */
    protected $tokensin = 0;

    /** @var int Output tokens used since the last clear_fallback_messages(). */
    protected $tokensout = 0;

    /** @var string[] Details of failures that were solved by a fallback in this request. */
    protected $fallbackmessages = [];

    /** @var string Finish reason reported by the provider for the last response. */
    protected $lastfinishreason = '';

    /** @var string Current provider key (the one being attempted). */
    protected $provider;
    /** @var string */
    protected $apikey;
    /** @var string */
    protected $model;
    /** @var string */
    protected $baseurl;
    /** @var float|null */
    protected $temperature;
    /** @var int */
    protected $timeout;
    /** @var bool */
    protected $jsonmode;
    /** @var int */
    protected $batchsize;

    /**
     * Constructor.
     *
     * @param string $provider gemini|openai|grok|custom
     * @param string $apikey API key.
     * @param string $model Model name.
     * @param string $baseurl Base URL.
     * @param float|null $temperature Sampling temperature (null = provider default).
     * @param int $timeout Request timeout in seconds.
     * @param bool $jsonmode Ask the provider for JSON output.
     * @param int $batchsize Questions requested per call.
     * @param array[]|null $profiles Optional list of provider profiles. When omitted, the first
     *     four arguments are used as the only profile.
     */
    public function __construct(string $provider, string $apikey, string $model, string $baseurl,
            ?float $temperature = 0.7, int $timeout = 120, bool $jsonmode = true, int $batchsize = 10,
            ?array $profiles = null) {
        $this->provider = isset(self::DEFAULTS[$provider]) ? $provider : 'gemini';
        $this->apikey = $apikey;
        $this->model = $model;
        $this->baseurl = $baseurl;
        $this->temperature = $temperature;
        $this->timeout = $timeout;
        $this->jsonmode = $jsonmode;
        $this->batchsize = $batchsize;

        if ($profiles === null) {
            $profiles = [[
                'label' => get_string('provider_main', 'local_ai_yared_course'),
                'provider' => $provider,
                'apikey' => $apikey,
                'model' => $model,
                'baseurl' => $baseurl,
                'jsonmode' => $jsonmode,
                'enabled' => true,
            ]];
        }

        $this->profiles = [];
        foreach ($profiles as $profile) {
            $this->profiles[] = self::normalise_profile($profile);
        }

        // Start with the first configured profile, so get_provider()/get_model() are useful before
        // the first request.
        foreach ($this->profiles as $index => $profile) {
            if (self::profile_is_configured($profile)) {
                $this->activeprofile = $index;
                $this->apply_profile($profile);
                break;
            }
        }
        if ($this->activeprofile < 0 && $this->profiles) {
            $this->apply_profile($this->profiles[0]);
        }
    }

    /**
     * Build a client from the plugin's admin settings (main provider plus optional fallbacks).
     *
     * @return self
     */
    public static function from_config(): self {
        $cfg = get_config('local_ai_yared_course');

        $temperature = (isset($cfg->temperature) && trim((string) $cfg->temperature) !== '')
            ? (float) $cfg->temperature : null;
        $timeout = max(10, (int) ($cfg->timeout ?? 600));
        $batchsize = max(1, min(20, (int) ($cfg->batchsize ?? 10)));

        return new static('gemini', '', '', '', $temperature, $timeout, true, $batchsize, self::profiles_from_config());
    }

    /**
     * Build a client for one settings slot, used by the "test connection" page.
     *
     * @param int $number 1 = main, 2..6 = backups 1..5.
     * @return self
     */
    public static function from_config_profile(int $number): self {
        $cfg = get_config('local_ai_yared_course');
        $number = max(1, min(self::MAX_SLOTS, $number));
        $suffix = $number === 1 ? '' : '_' . $number;

        $label = $number === 1
            ? get_string('provider_main', 'local_ai_yared_course')
            : get_string('provider_fallback', 'local_ai_yared_course', $number - 1);

        $profile = self::normalise_profile([
            'label' => $label,
            'provider' => $cfg->{'provider' . $suffix} ?? 'gemini',
            'apikey' => $cfg->{'apikey' . $suffix} ?? '',
            'model' => $cfg->{'model' . $suffix} ?? '',
            'baseurl' => $cfg->{'baseurl' . $suffix} ?? '',
            'jsonmode' => $cfg->{'jsonmode' . $suffix} ?? true,
            'enabled' => $number === 1
                ? (!isset($cfg->enabled) || !empty($cfg->enabled))
                : !empty($cfg->{'enabled' . $suffix}),
        ]);

        $temperature = (isset($cfg->temperature) && trim((string) $cfg->temperature) !== '')
            ? (float) $cfg->temperature : null;
        $timeout = max(10, (int) ($cfg->timeout ?? 600));
        $batchsize = max(1, min(20, (int) ($cfg->batchsize ?? 10)));

        return new static('gemini', '', '', '', $temperature, $timeout, true, $batchsize, [$profile]);
    }

    /**
     * Read the enabled and configured profiles from the plugin settings.
     *
     * @return array[]
     */
    protected static function profiles_from_config(): array {
        $cfg = get_config('local_ai_yared_course');
        $slots = [];
        for ($i = 1; $i <= self::MAX_SLOTS; $i++) {
            $slots[] = ['suffix' => $i === 1 ? '' : '_' . $i, 'number' => $i];
        }

        $profiles = [];
        foreach ($slots as $slot) {
            $suffix = $slot['suffix'];
            $number = $slot['number'];

            $enabled = $number === 1
                ? (!isset($cfg->enabled) || !empty($cfg->enabled))
                : !empty($cfg->{'enabled' . $suffix});
            if (!$enabled) {
                continue;
            }

            $label = $number === 1
                ? get_string('provider_main', 'local_ai_yared_course')
                : get_string('provider_fallback', 'local_ai_yared_course', $number - 1);

            $profile = self::normalise_profile([
                'label' => $label,
                'provider' => $cfg->{'provider' . $suffix} ?? 'gemini',
                'apikey' => $cfg->{'apikey' . $suffix} ?? '',
                'model' => $cfg->{'model' . $suffix} ?? '',
                'baseurl' => $cfg->{'baseurl' . $suffix} ?? '',
                'jsonmode' => $cfg->{'jsonmode' . $suffix} ?? true,
                'enabled' => true,
            ]);

            if (!self::profile_is_configured($profile)) {
                debugging('local_ai_yared_course: AI profile "' . $label . '" is enabled but incomplete.',
                    DEBUG_DEVELOPER);
                continue;
            }

            $profiles[] = $profile;
        }

        return $profiles;
    }

    /**
     * Fill in the provider defaults and normalise one profile.
     *
     * @param array $profile Raw profile.
     * @return array
     */
    protected static function normalise_profile(array $profile): array {
        $provider = (string) ($profile['provider'] ?? 'gemini');
        if (!isset(self::DEFAULTS[$provider])) {
            $provider = 'gemini';
        }

        $baseurl = trim((string) ($profile['baseurl'] ?? ''));
        if ($baseurl === '') {
            $baseurl = self::DEFAULTS[$provider]['baseurl'];
        }

        $model = trim((string) ($profile['model'] ?? ''));
        if ($model === '') {
            $model = self::DEFAULTS[$provider]['model'];
        }

        return [
            'label' => (string) ($profile['label'] ?? get_string('provider_main', 'local_ai_yared_course')),
            'provider' => $provider,
            'apikey' => trim((string) ($profile['apikey'] ?? '')),
            'model' => $model,
            'baseurl' => $baseurl,
            'jsonmode' => !isset($profile['jsonmode']) || !empty($profile['jsonmode']),
            'enabled' => !isset($profile['enabled']) || !empty($profile['enabled']),
        ];
    }

    /**
     * Whether a profile has the minimum data needed to make a request.
     *
     * @param array $profile Profile.
     * @return bool
     */
    protected static function profile_is_configured(array $profile): bool {
        if (empty($profile['model']) || empty($profile['baseurl'])) {
            return false;
        }
        // Self-hosted OpenAI-compatible servers (e.g. Ollama) may not need a key.
        return $profile['apikey'] !== '' || $profile['provider'] === 'custom';
    }

    /**
     * Copy a profile to the properties used by the request builders.
     *
     * @param array $profile Profile.
     */
    protected function apply_profile(array $profile): void {
        $this->provider = $profile['provider'];
        $this->apikey = $profile['apikey'];
        $this->model = $profile['model'];
        $this->baseurl = $profile['baseurl'];
        $this->jsonmode = $profile['jsonmode'];
    }

    /**
     * Whether at least one profile is complete enough to call the provider.
     *
     * @return bool
     */
    public function is_configured(): bool {
        foreach ($this->profiles as $profile) {
            if (self::profile_is_configured($profile)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return string Active provider key.
     */
    public function get_provider(): string {
        if (isset($this->profiles[$this->activeprofile])) {
            return $this->profiles[$this->activeprofile]['provider'];
        }
        return $this->provider;
    }

    /**
     * @return string Active provider name, translated for humans.
     */
    public function get_provider_name(): string {
        return $this->provider_name($this->get_provider());
    }

    /**
     * @return string Active model name.
     */
    public function get_model(): string {
        if (isset($this->profiles[$this->activeprofile])) {
            return $this->profiles[$this->activeprofile]['model'];
        }
        return $this->model;
    }

    /**
     * @return string Label of the active profile ("Principal", "Respaldo 1", ...).
     */
    public function get_active_profile_label(): string {
        if (isset($this->profiles[$this->activeprofile])) {
            return $this->profiles[$this->activeprofile]['label'];
        }
        if ($this->profiles) {
            return $this->profiles[0]['label'];
        }
        return '';
    }

    /**
     * @return int Number of configured providers (a second one is needed to ask for "another model").
     */
    public function count_usable(): int {
        $count = 0;
        foreach ($this->profiles as $profile) {
            $count += self::profile_is_configured($profile) ? 1 : 0;
        }
        return $count;
    }

    /**
     * Ask for a different provider than the one that produced the previous answer.
     *
     * The avoided profile is only tried when every other one fails. It does nothing when a single
     * provider is configured.
     *
     * @param string $label Label returned earlier by get_active_profile_label().
     */
    public function avoid_profile(string $label): void {
        $this->avoidlabel = $label;
    }

    /**
     * @return int Questions requested per API call.
     */
    public function get_batchsize(): int {
        return $this->batchsize;
    }

    /**
     * Details of provider failures that were solved by another provider.
     *
     * @return string[]
     */
    public function get_fallback_messages(): array {
        return $this->fallbackmessages;
    }

    /**
     * Finish reason reported by the provider for the last response.
     *
     * Common values are STOP, MAX_TOKENS and SAFETY for Gemini, and stop or length for
     * OpenAI-compatible providers.
     *
     * @return string
     */
    public function get_last_finish_reason(): string {
        return $this->lastfinishreason;
    }

    /**
     * Forget the fallback details, normally at the start of a new topic.
     */
    public function clear_fallback_messages(): void {
        $this->tokensin = 0;
        $this->tokensout = 0;
        $this->fallbackmessages = [];
    }

    /**
     * Send a prompt and return the raw text of the answer.
     *
     * Tries the active profile first, then the remaining profiles in order. A profile is skipped
     * when it is incomplete. If every profile fails, the last error is thrown when there is only
     * one profile; otherwise a summary with every failure is thrown.
     *
     * @param string $system System instructions.
     * @param string $user User prompt.
     * @return string Model output.
     * @throws \moodle_exception On configuration, connection or provider errors.
     */
    public function complete(string $system, string $user): string {
        return $this->complete_parts($system, $user, []);
    }

    /**
     * Send a multimodal prompt (text plus images or a PDF) and return the raw text of the answer.
     *
     * PDF attachments are only supported by Gemini, because the other providers do not accept a
     * PDF directly. Profiles that cannot read one of the attachments are skipped.
     *
     * @param string $system System instructions.
     * @param string $user User prompt.
     * @param array[] $files Attachments: [['mime' => 'image/jpeg', 'data' => '<base64>'], ...].
     * @return string Model output.
     * @throws \moodle_exception On configuration, connection or provider errors.
     */
    public function complete_multimodal(string $system, string $user, array $files): string {
        if (!$files) {
            return $this->complete($system, $user);
        }
        return $this->complete_parts($system, $user, $files);
    }

    /**
     * Shared implementation of complete() and complete_multimodal().
     *
     * @param string $system System instructions.
     * @param string $user User prompt.
     * @param array[] $files Attachments.
     * @return string Model output.
     * @throws \moodle_exception On configuration, connection or provider errors.
     */
    protected function complete_parts(string $system, string $user, array $files): string {
        if (!$this->is_configured()) {
            throw new \moodle_exception('notconfigured', 'local_ai_yared_course');
        }

        $attempts = [];
        $last = null;
        $applicable = 0;

        // One attempt per provider and per API key. Keys of the same provider are rotated (round robin) so
        // the load is shared, and anything the circuit breaker marked as failing goes to the end of the
        // list: it is still tried if nothing else works, but healthy providers answer first.
        $queue = [];
        foreach ($this->get_profile_order() as $index) {
            $profile = $this->profiles[$index];
            if (!self::profile_is_configured($profile)) {
                continue;
            }
            if ($files && !self::profile_supports_files($profile, $files)) {
                continue;
            }
            $applicable++;
            $keys = ai_guard::split_keys((string) $profile['apikey']);
            $offset = ai_guard::rotate('p' . $index . '_' . $profile['provider'], count($keys));
            for ($k = 0; $k < count($keys); $k++) {
                $key = $keys[($k + $offset) % count($keys)];
                $label = $profile['label'] . (count($keys) > 1 ? ' #' . ((($k + $offset) % count($keys)) + 1) : '');
                $guardkey = ai_guard::key($profile['provider'], $key);
                $queue[] = [
                    'index' => $index,
                    'key' => $key,
                    'label' => $label,
                    'guardkey' => $guardkey,
                    'tripped' => ai_guard::is_tripped($guardkey),
                ];
            }
        }
        usort($queue, function ($a, $b) {
            // PHP's sort is stable since 8.0, so the configured order is kept inside each group.
            return (int) $a['tripped'] <=> (int) $b['tripped'];
        });

        foreach ($queue as $attempt) {
            $index = $attempt['index'];
            $profile = $this->profiles[$index];
            $profile['apikey'] = $attempt['key'];

            $this->apply_profile($profile);
            $lock = ai_guard::acquire($attempt['guardkey']);
            try {
                $text = $this->request_once($system, $user, $files);
                ai_guard::clear($attempt['guardkey']);
                $this->activeprofile = $index;
                if ($attempts) {
                    $this->fallbackmessages = array_merge($this->fallbackmessages, $attempts);
                }
                return $text;
            } catch (\moodle_exception $e) {
                $last = $e;
                $attempts[] = $attempt['label'] . ': ' . $e->getMessage();
                $this->trip_after_failure($attempt['guardkey']);
                // Only failures are logged (never successful calls), with the reason, for the admin report.
                usage_log::ai_failure($attempt['label'], $profile['provider'], $profile['model'], $attempt['key'],
                    $this->classify_failure($e), max(0, $this->lasthttp), $e->getMessage());
                debugging('local_ai_yared_course: AI provider "' . $attempt['label'] . '" failed: ' .
                    $e->getMessage(), DEBUG_DEVELOPER);
            } finally {
                if ($lock) {
                    $lock->release();
                }
            }
        }

        if (!$applicable) {
            $haspdf = false;
            foreach ($files as $file) {
                $haspdf = $haspdf || strtolower((string) ($file['mime'] ?? '')) === 'application/pdf';
            }
            throw new \moodle_exception($haspdf ? 'error_nopdf' : 'error_novision', 'local_ai_yared_course');
        }

        if (!$attempts) {
            throw new \moodle_exception('notconfigured', 'local_ai_yared_course');
        }

        if ($last !== null && count($attempts) === 1) {
            throw $last;
        }

        throw new \moodle_exception('error_allproviders', 'local_ai_yared_course', '',
            implode(' | ', $attempts));
    }

    /**
     * Change the sampling temperature for the next requests.
     *
     * @param float|null $temperature Temperature, or null to use the provider default.
     */
    public function set_temperature(?float $temperature): void {
        $this->temperature = $temperature;
    }

    /**
     * Whether at least one configured profile can read a PDF natively (Gemini).
     *
     * @return bool
     */
    public function supports_pdf(): bool {
        foreach ($this->profiles as $profile) {
            if (self::profile_is_configured($profile) && $profile['provider'] === 'gemini') {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether a profile can read every attachment in the request.
     *
     * @param array $profile Profile.
     * @param array[] $files Attachments.
     * @return bool
     */
    protected static function profile_supports_files(array $profile, array $files): bool {
        foreach ($files as $file) {
            if (strtolower((string) ($file['mime'] ?? '')) === 'application/pdf'
                    && $profile['provider'] !== 'gemini') {
                return false;
            }
        }
        return true;
    }

    /**
     * Order in which profiles are tried: the last one that worked goes first.
     *
     * @return int[]
     */
    protected function get_profile_order(): array {
        $order = [];
        if (isset($this->profiles[$this->activeprofile])) {
            $order[] = $this->activeprofile;
        }
        foreach (array_keys($this->profiles) as $index) {
            if ($index !== $this->activeprofile) {
                $order[] = $index;
            }
        }
        if ($this->avoidlabel !== '' && count($order) > 1) {
            $keep = [];
            $last = [];
            foreach ($order as $index) {
                if (($this->profiles[$index]['label'] ?? '') === $this->avoidlabel) {
                    $last[] = $index;
                } else {
                    $keep[] = $index;
                }
            }
            $order = array_merge($keep, $last);
        }
        return $order;
    }

    /**
     * Make one request with the profile currently copied to the instance properties.
     *
     * @param string $system System instructions.
     * @param string $user User prompt.
     * @return string Model output.
     * @throws \moodle_exception On connection or provider errors.
     */
    protected function request_once(string $system, string $user, array $files = []): string {
        $this->lastfinishreason = '';
        $this->lasthttp = -1;
        $this->lasterrno = 0;
        if ($this->provider === 'gemini') {
            [$url, $headers, $body] = $this->build_gemini_request($system, $user, $files);
        } else {
            [$url, $headers, $body] = $this->build_openai_request($system, $user, $files);
        }

        $curl = new \curl();
        $curl->setHeader($headers);
        $response = $curl->post($url, json_encode($body, JSON_UNESCAPED_UNICODE), [
            'CURLOPT_TIMEOUT' => $this->timeout,
            'CURLOPT_CONNECTTIMEOUT' => 20,
        ]);

        if ($curl->get_errno()) {
            $this->lasthttp = 0;
            $this->lasterrno = (int) $curl->get_errno();
            throw new \moodle_exception('error_ai', 'local_ai_yared_course', '',
                get_string('error_connection', 'local_ai_yared_course', s((string) $curl->error)));
        }

        $info = $curl->get_info();
        $http = (int) ($info['http_code'] ?? 0);
        $this->lasthttp = $http;
        $data = json_decode((string) $response, true);

        if ($http < 200 || $http >= 300) {
            $message = '';
            if (is_array($data)) {
                $err = $data['error'] ?? '';
                $message = is_array($err) ? (string) ($err['message'] ?? '') : (string) $err;
            }
            if ($message === '') {
                $message = trim(strip_tags((string) $response));
            }
            $this->throw_http_error($http, $message, !empty($files));
        }

        if (!is_array($data)) {
            throw new \moodle_exception('error_ai', 'local_ai_yared_course', '',
                get_string('error_emptyresponse', 'local_ai_yared_course'));
        }

        if ($this->provider === 'gemini') {
            $this->tokensin += (int) ($data['usageMetadata']['promptTokenCount'] ?? 0);
            $this->tokensout += (int) ($data['usageMetadata']['candidatesTokenCount'] ?? 0);
        } else {
            $this->tokensin += (int) ($data['usage']['prompt_tokens'] ?? 0);
            $this->tokensout += (int) ($data['usage']['completion_tokens'] ?? 0);
        }
        $this->lastfinishreason = $this->provider === 'gemini'
            ? (string) ($data['candidates'][0]['finishReason'] ?? '')
            : (string) ($data['choices'][0]['finish_reason'] ?? '');
        $text = $this->provider === 'gemini' ? $this->extract_gemini($data) : $this->extract_openai($data);
        if (trim($text) === '') {
            throw new \moodle_exception('error_ai', 'local_ai_yared_course', '',
                get_string('error_emptyresponse', 'local_ai_yared_course'));
        }
        return $text;
    }

    /**
     * Tokens used since the start of the current topic.
     *
     * @return array ['in' => int, 'out' => int]
     */
    public function get_usage(): array {
        return ['in' => $this->tokensin, 'out' => $this->tokensout];
    }

    /**
     * Name of the failure category of an exception, for the usage log.
     *
     * @param \moodle_exception $e Exception thrown by request_once().
     * @return string
     */
    protected function classify_failure(\moodle_exception $e): string {
        switch ($e->errorcode) {
            case 'error_quota':
                return 'quota';
            case 'error_modelnotfound':
                return 'model';
            case 'error_auth':
                return 'auth';
            case 'error_visionunsupported':
                return 'vision';
            case 'error_blocked':
                return 'blocked';
        }
        if ($this->lasthttp === 0) {
            return $this->lasterrno === 28 ? 'timeout' : 'connection';
        }
        if ($this->lasthttp >= 500) {
            return 'server';
        }
        if ($this->lasthttp >= 200 && $this->lasthttp < 300) {
            return 'empty';
        }
        return 'other';
    }

    /**
     * Tell the shared circuit breaker that the last request failed for a reason that is not the prompt.
     *
     * Quota (429), server (5xx) and connection errors skip the provider key for a short time; wrong
     * key or model (401, 403, 404) for longer. Other errors (for example an unsupported image) do not
     * affect other users.
     *
     * @param string $guardkey Value of ai_guard::key().
     */
    protected function trip_after_failure(string $guardkey): void {
        $http = $this->lasthttp;
        $base = (int) get_config('local_ai_yared_course', 'aitripseconds');
        $base = $base > 0 ? $base : 60;
        if ($http === 401 || $http === 403 || $http === 404) {
            ai_guard::trip($guardkey, max($base, 300));
        } else if ($http === 0 || $http === 429 || $http >= 500) {
            ai_guard::trip($guardkey, $base);
        }
    }

    /**
     * Throw a translated exception for the most common HTTP errors.
     *
     * @param int $http HTTP status.
     * @param string $message Raw provider message.
     * @param bool $multimodal Whether the request included images or a PDF.
     * @throws \moodle_exception Always.
     */
    protected function throw_http_error(int $http, string $message, bool $multimodal = false): void {
        debugging('local_ai_yared_course: HTTP ' . $http . ' from ' . $this->provider . ': ' . $message,
            DEBUG_DEVELOPER);

        $providername = $this->provider_name($this->provider);
        $model = $this->model;

        if ($http === 400 && $multimodal
                && preg_match('/image|media|vision|multimodal|unsupported|content[- ]type/i', $message)) {
            throw new \moodle_exception('error_visionunsupported', 'local_ai_yared_course', '', (object) [
                'provider' => $providername,
                'model' => $model,
            ]);
        }

        if ($http === 429) {
            $retry = '';
            if (preg_match('/retry in ([0-9hms\.]+)/i', $message, $matches)) {
                $retry = get_string('error_quota_retry', 'local_ai_yared_course', $matches[1]);
            } else {
                $retry = get_string('error_quota_wait', 'local_ai_yared_course');
            }
            throw new \moodle_exception('error_quota', 'local_ai_yared_course', '', (object) [
                'provider' => $providername,
                'model' => $model,
                'retry' => $retry,
            ]);
        }

        if ($http === 404) {
            throw new \moodle_exception('error_modelnotfound', 'local_ai_yared_course', '', (object) [
                'provider' => $providername,
                'model' => $model,
            ]);
        }

        if ($http === 401 || $http === 403) {
            throw new \moodle_exception('error_auth', 'local_ai_yared_course', '', (object) [
                'provider' => $providername,
                'model' => $model,
            ]);
        }

        throw new \moodle_exception('error_ai', 'local_ai_yared_course', '',
            'HTTP ' . $http . ' - ' . s(\core_text::substr($message, 0, 400)));
    }

    /**
     * Human-readable provider name.
     *
     * @param string $provider Provider key.
     * @return string
     */
    protected function provider_name(string $provider): string {
        $name = get_string('provider_' . $provider, 'local_ai_yared_course');
        return strpos($name, '[[') !== false ? $provider : $name;
    }

    /**
     * Build a Gemini generateContent request.
     *
     * @param string $system
     * @param string $user
     * @param array[] $files Attachments (mime + base64 data).
     * @return array [url, headers, body]
     */
    protected function build_gemini_request(string $system, string $user, array $files = []): array {
        $model = preg_replace('~^models/~', '', $this->model);
        $url = rtrim($this->baseurl, '/') . '/models/' . rawurlencode($model) . ':generateContent';
        $headers = [
            'Content-Type: application/json',
            'x-goog-api-key: ' . $this->apikey,
        ];
        $generation = [];
        if ($this->temperature !== null) {
            $generation['temperature'] = $this->temperature;
        }
        if ($this->jsonmode) {
            $generation['responseMimeType'] = 'application/json';
        }
        // Leave room for the whole JSON answer; otherwise the response can be cut off mid-JSON.
        $generation['maxOutputTokens'] = self::MAX_OUTPUT_TOKENS;
        // Gemini 2.5 models "think" by default and those reasoning tokens eat into the output
        // budget, which truncates the JSON. Disable it for flash and minimise it for pro.
        if (preg_match('/2\.5/', $model)) {
            $generation['thinkingConfig'] = ['thinkingBudget' => preg_match('/pro/i', $model) ? 128 : 0];
        }
        $parts = [['text' => $user]];
        foreach ($files as $file) {
            $parts[] = ['inline_data' => [
                'mime_type' => (string) ($file['mime'] ?? 'application/octet-stream'),
                'data' => (string) ($file['data'] ?? ''),
            ]];
        }
        $body = [
            'systemInstruction' => ['parts' => [['text' => $system]]],
            'contents' => [['role' => 'user', 'parts' => $parts]],
        ];
        if ($generation) {
            $body['generationConfig'] = $generation;
        }
        return [$url, $headers, $body];
    }

    /**
     * Build an OpenAI-style chat completions request (OpenAI, Grok, custom).
     *
     * @param string $system
     * @param string $user
     * @param array[] $files Attachments (mime + base64 data).
     * @return array [url, headers, body]
     */
    protected function build_openai_request(string $system, string $user, array $files = []): array {
        $base = rtrim($this->baseurl, '/');
        $url = preg_match('~/chat/completions$~', $base) ? $base : $base . '/chat/completions';
        $headers = ['Content-Type: application/json'];
        if ($this->apikey !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->apikey;
        }
        $content = $user;
        if ($files) {
            $content = [['type' => 'text', 'text' => $user]];
            foreach ($files as $file) {
                $content[] = ['type' => 'image_url', 'image_url' => [
                    'url' => 'data:' . (string) ($file['mime'] ?? 'image/jpeg') . ';base64,'
                        . (string) ($file['data'] ?? ''),
                    'detail' => 'high',
                ]];
            }
        }
        $body = [
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $content],
            ],
        ];
        // Reasoning models (gpt-5*, o1/o3...) only accept the default temperature.
        if ($this->temperature !== null && !preg_match('/^(gpt-5|o\d)/i', $this->model)) {
            $body['temperature'] = $this->temperature;
        }
        if ($this->jsonmode) {
            $body['response_format'] = ['type' => 'json_object'];
        }
        if (isset(self::PROVIDER_MAX_TOKENS[$this->provider])) {
            // Without it DeepSeek answers with only 4096 tokens, which cuts the JSON short.
            $body['max_tokens'] = self::PROVIDER_MAX_TOKENS[$this->provider];
        }
        return [$url, $headers, $body];
    }

    /**
     * Extract the text from a Gemini response.
     *
     * @param array $data Decoded response.
     * @return string
     */
    protected function extract_gemini(array $data): string {
        $text = '';
        foreach ($data['candidates'][0]['content']['parts'] ?? [] as $part) {
            // Thinking models may return their reasoning as separate parts; never mix it with the answer.
            if (!empty($part['thought'])) {
                continue;
            }
            $text .= $part['text'] ?? '';
        }
        if ($text === '' && !empty($data['promptFeedback']['blockReason'])) {
            throw new \moodle_exception('error_ai', 'local_ai_yared_course', '',
                get_string('error_blocked', 'local_ai_yared_course', s((string) $data['promptFeedback']['blockReason'])));
        }
        return $text;
    }

    /**
     * Extract the text from an OpenAI-style response.
     *
     * @param array $data Decoded response.
     * @return string
     */
    protected function extract_openai(array $data): string {
        $content = $data['choices'][0]['message']['content'] ?? '';
        return is_string($content) ? $content : '';
    }
}
