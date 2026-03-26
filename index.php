<?php
declare(strict_types=1);

date_default_timezone_set('Europe/Amsterdam');

/**
 * Optional overrides.
 * - owner_name: force outgoing bubble mapping for your own name in the export.
 * - title: header title override.
 * - profile_photo: absolute or relative path to profile image.
 */
$settings = [
    'owner_name' => null,
    'title' => null,
    'profile_photo' => null,
];

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function stripControlChars(string $value): string
{
    $value = str_replace("\u{FEFF}", '', $value);
    return preg_replace('/[\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $value) ?? $value;
}

function normalizeLine(string $line): string
{
    return stripControlChars(rtrim($line, "\r\n"));
}

function pathToUrl(string $absolutePath): ?string
{
    $root = realpath(__DIR__);
    $real = realpath($absolutePath);
    if ($root === false || $real === false) {
        return null;
    }

    if (!str_starts_with($real, $root)) {
        return null;
    }

    $relative = ltrim(substr($real, strlen($root)), DIRECTORY_SEPARATOR);
    if ($relative === '') {
        return './';
    }

    $segments = explode(DIRECTORY_SEPARATOR, $relative);
    return implode('/', array_map(static fn(string $segment): string => rawurlencode($segment), $segments));
}

function findChatFile(string $baseDir): ?string
{
    $candidates = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($baseDir, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $item) {
        if (!$item instanceof SplFileInfo || !$item->isFile()) {
            continue;
        }
        if (strcasecmp($item->getFilename(), '_chat.txt') === 0) {
            $candidates[] = $item->getPathname();
        }
    }

    if ($candidates === []) {
        return null;
    }

    sort($candidates);
    return $candidates[0];
}

function parseDateTime(string $datePart, string $timePart): ?DateTimeImmutable
{
    $datePart = trim($datePart);
    $timePart = trim(str_replace("\u{202F}", ' ', $timePart));

    if (!preg_match('/^(\d{1,2})([\/\-.])(\d{1,2})\2(\d{2,4})$/', $datePart, $dateMatch)) {
        return null;
    }

    $day = (int) $dateMatch[1];
    $month = (int) $dateMatch[3];
    $year = (int) $dateMatch[4];
    if ($year < 100) {
        $year += 2000;
    }

    $formats = ['j/n/Y g:i:s A', 'j/n/Y g:i A', 'j/n/Y H:i:s', 'j/n/Y H:i'];
    $dateString = sprintf('%d/%d/%d %s', $day, $month, $year, $timePart);

    foreach ($formats as $format) {
        $parsed = DateTimeImmutable::createFromFormat($format, $dateString);
        if ($parsed instanceof DateTimeImmutable) {
            return $parsed;
        }
    }

    return null;
}

function matchChatLine(string $line): ?array
{
    $patterns = [
        '/^\[(\d{1,2}[\/\-.]\d{1,2}[\/\-.]\d{2,4}),\s(.+?)\]\s(.*)$/u',
        '/^(\d{1,2}[\/\-.]\d{1,2}[\/\-.]\d{2,4}),\s(.+?)\s-\s(.*)$/u',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $line, $match) === 1) {
            return [
                'date' => trim($match[1]),
                'time' => trim(str_replace("\u{202F}", ' ', $match[2])),
                'payload' => trim($match[3]),
            ];
        }
    }

    return null;
}

/**
 * @return array<int, array<string, mixed>>
 */
function parseChatMessages(string $chatFile): array
{
    $lines = file($chatFile, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return [];
    }

    $messages = [];
    $current = null;

    foreach ($lines as $rawLine) {
        $line = normalizeLine((string) $rawLine);
        $matchedLine = matchChatLine($line);
        if ($matchedLine !== null) {
            if (is_array($current)) {
                $messages[] = $current;
            }

            $dateRaw = $matchedLine['date'];
            $timeRaw = $matchedLine['time'];
            $payload = $matchedLine['payload'];

            $sender = null;
            $text = $payload;
            $type = 'system';

            if (preg_match('/^([^:]+):\s?(.*)$/u', $payload, $payloadMatch)) {
                $sender = trim(stripControlChars($payloadMatch[1]));
                $text = (string) $payloadMatch[2];
                $type = 'message';
            }

            $current = [
                'date_raw' => $dateRaw,
                'time_raw' => $timeRaw,
                'datetime' => parseDateTime($dateRaw, $timeRaw),
                'sender' => $sender,
                'text' => trim(stripControlChars($text)),
                'type' => $type,
            ];
            continue;
        }

        if (!is_array($current)) {
            continue;
        }

        $continuation = trim(stripControlChars($line));
        if ($continuation === '' && $current['text'] === '') {
            continue;
        }

        if ($current['text'] === '') {
            $current['text'] = $continuation;
        } else {
            $current['text'] .= "\n" . $continuation;
        }
    }

    if (is_array($current)) {
        $messages[] = $current;
    }

    $normalized = [];
    foreach ($messages as $message) {
        $text = (string) $message['text'];
        $attachment = null;
        $mediaOmitted = null;
        $edited = false;

        if (preg_match('/<attached:\s*([^>]+)>/iu', $text, $attachmentMatch)) {
            $attachment = trim($attachmentMatch[1]);
            $text = trim((string) preg_replace('/<attached:\s*[^>]+>/iu', '', $text));
        }

        if (preg_match('/\b(image|video|audio|sticker|document)\s+omitted\b/iu', $text, $omittedMatch)) {
            $mediaOmitted = strtolower($omittedMatch[1]);
            $text = trim((string) preg_replace('/\b(image|video|audio|sticker|document)\s+omitted\b/iu', '', $text));
        }

        if (stripos($text, '<This message was edited>') !== false) {
            $edited = true;
            $text = trim(str_ireplace('<This message was edited>', '', $text));
        }

        $message['text'] = $text;
        $message['attachment'] = $attachment;
        $message['media_omitted'] = $mediaOmitted;
        $message['edited'] = $edited;

        if (
            $message['type'] === 'message'
            && $message['text'] === ''
            && $message['attachment'] === null
            && $message['media_omitted'] === null
            && $message['edited'] === false
        ) {
            continue;
        }

        $normalized[] = $message;
    }

    return $normalized;
}

function formatDateChip(?DateTimeImmutable $dateTime, string $fallback): string
{
    if (!$dateTime) {
        return $fallback;
    }

    $months = [1 => 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    $month = $months[(int) $dateTime->format('n')] ?? $dateTime->format('m');
    return $dateTime->format('j') . ' ' . $month . ' ' . $dateTime->format('Y');
}

function formatTimeLabel(?DateTimeImmutable $dateTime, string $fallback): string
{
    if (!$dateTime) {
        return $fallback;
    }
    return $dateTime->format('H:i');
}

function renderMessageText(string $text): string
{
    if ($text === '') {
        return '';
    }

    $escaped = e($text);
    $linked = preg_replace_callback(
        '~(https?://[^\s<]+)~iu',
        static function (array $match): string {
            $url = $match[1];
            $safeUrl = e($url);
            return '<a href="' . $safeUrl . '" target="_blank" rel="noopener noreferrer">' . $safeUrl . '</a>';
        },
        $escaped
    );

    return nl2br((string) $linked);
}

function shouldInlineMetaWithText(string $text, int $maxChars = 30): bool
{
    $normalized = trim(str_replace(["\r\n", "\r"], "\n", stripControlChars($text)));
    if ($normalized === '') {
        return false;
    }

    if (str_contains($normalized, "\n")) {
        return false;
    }

    if (preg_match('~https?://~iu', $normalized) === 1) {
        return false;
    }

    $length = function_exists('mb_strlen') ? mb_strlen($normalized, 'UTF-8') : strlen($normalized);
    return $length <= $maxChars;
}

function firstInitial(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '?';
    }

    if (preg_match('/[\p{L}\p{N}]/u', $value, $match) === 1) {
        return $match[0];
    }

    return substr($value, 0, 1);
}

function folderDisplayName(string $folderPath): string
{
    $folderName = trim(basename($folderPath));

    if (preg_match('/^WhatsApp Chat\s*-\s*(.+)$/iu', $folderName, $match) === 1) {
        $name = trim($match[1]);
        return $name !== '' ? $name : 'Contact';
    }

    return $folderName !== '' ? $folderName : 'Contact';
}

function comparableName(string $value): string
{
    $value = trim(stripControlChars($value));
    $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? $value;
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

    if (function_exists('mb_strtolower')) {
        return mb_strtolower(trim($value), 'UTF-8');
    }

    return strtolower(trim($value));
}

function samePerson(?string $left, ?string $right): bool
{
    return comparableName((string) $left) === comparableName((string) $right);
}

function containsAnyTextToken(string $haystack, array $tokens): bool
{
    foreach ($tokens as $token) {
        if (str_contains($haystack, $token)) {
            return true;
        }
    }

    return false;
}

function detectWhatsAppNoticeType(?string $sender, string $text, ?string $messageType = null): ?string
{
    $normalizedText = comparableName($text);
    if ($normalizedText === '') {
        return $messageType === 'system' ? 'system' : null;
    }

    $isEncryptionNotice = str_contains($normalizedText, 'end to end encrypted')
        || str_contains($normalizedText, 'end to end versleuteld')
        || (str_contains($normalizedText, 'messages and calls') && str_contains($normalizedText, 'only people in this chat'));
    if ($isEncryptionNotice) {
        return 'encryption';
    }

    $isSecurityCodeEn = str_contains($normalizedText, 'security code')
        && str_contains($normalizedText, 'changed');
    $isSecurityCodeNl = str_contains($normalizedText, 'beveiligingscode')
        && (str_contains($normalizedText, 'gewijzigd') || str_contains($normalizedText, 'veranderd'));
    if ($isSecurityCodeEn || $isSecurityCodeNl) {
        return 'number_change';
    }

    $normalizedSender = comparableName((string) $sender);
    $looksLikeSystemSender = $normalizedSender === ''
        || str_contains($normalizedSender, 'system')
        || str_contains($normalizedSender, 'whatsapp');
    if ($looksLikeSystemSender) {
        $isNumberChangeEn = str_contains($normalizedText, 'changed')
            && str_contains($normalizedText, 'phone number')
            && str_contains($normalizedText, 'new number');
        $isNumberChangeNl = str_contains($normalizedText, 'nummer')
            && (str_contains($normalizedText, 'gewijzigd') || str_contains($normalizedText, 'veranderd'))
            && str_contains($normalizedText, 'nieuw');

        if ($isNumberChangeEn || $isNumberChangeNl) {
            return 'number_change';
        }
    }

    $isGroupEvent = containsAnyTextToken(
        $normalizedText,
        [
            'added you',
            'added ',
            ' added ',
            'removed you',
            'removed ',
            ' removed ',
            ' left',
            ' joined',
            'created group',
            'created this group',
            'changed the group',
            'changed this group',
            'you deleted this message',
            'deleted this message',
            'this message was deleted',
            'changed subject',
            'changed description',
            'changed the icon',
            'made you an admin',
            'made this group',
            'group invite',
            'invite link',
            'disappearing messages',
            ' verdwijnende berichten',
            'heeft toegevoegd',
            'is toegevoegd',
            'heeft verwijderd',
            'heeft de groeps',
            'heeft het groeps',
            'heeft de groep',
            'heeft deze groep',
            'heeft onderwerp',
            'heeft beschrijving',
            'heeft pictogram',
            'heeft icoon',
            'heeft je toegevoegd',
            'heeft je verwijderd',
            'is lid geworden',
            'heeft de uitnodigingslink',
            'beheerders',
            'admin',
        ]
    );
    if ($isGroupEvent) {
        return 'group_event';
    }

    $isCallEvent = containsAnyTextToken(
        $normalizedText,
        [
            'missed voice call',
            'missed video call',
            'voice call',
            'video call',
            'spraakoproep',
            'videogesprek',
            'gemiste oproep',
        ]
    );
    if ($isCallEvent) {
        return 'call_event';
    }

    $isDisappearingToggle = str_contains($normalizedText, 'disappearing messages')
        || str_contains($normalizedText, 'verdwijnende berichten');
    if ($isDisappearingToggle) {
        return 'privacy_event';
    }

    if ($messageType === 'system') {
        return 'system';
    }

    return null;
}

function senderColorPalette(string $sender): array
{
    $normalized = comparableName($sender);
    $hash = $normalized !== '' ? crc32($normalized) : crc32($sender);
    $hue = abs((int) $hash) % 360;

    return [
        'text' => sprintf('hsl(%d 68%% 34%%)', $hue),
        'bg' => sprintf('hsl(%d 78%% 91%%)', $hue),
    ];
}

function findNewestFileInDirectory(string $directory): ?string
{
    if (!is_dir($directory)) {
        return null;
    }

    $files = [];
    $iterator = new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS);
    foreach ($iterator as $item) {
        if (!$item instanceof SplFileInfo || !$item->isFile()) {
            continue;
        }

        $filename = $item->getFilename();
        if (str_starts_with($filename, '.')) {
            continue;
        }

        $files[] = $item->getPathname();
    }

    if ($files === []) {
        return null;
    }

    usort(
        $files,
        static function (string $a, string $b): int {
            $mtimeCompare = (filemtime($b) ?: 0) <=> (filemtime($a) ?: 0);
            if ($mtimeCompare !== 0) {
                return $mtimeCompare;
            }

            return strcasecmp(basename($a), basename($b));
        }
    );

    return $files[0];
}

function findProfilePhoto(?string $configured, string $chatDir): ?string
{
    $candidates = [];

    if (is_string($configured) && $configured !== '') {
        $isAbsolute = str_starts_with($configured, DIRECTORY_SEPARATOR) || preg_match('/^[A-Za-z]:\\\\/', $configured);
        $candidates[] = $isAbsolute ? $configured : __DIR__ . DIRECTORY_SEPARATOR . $configured;
    }

    $folderCandidate = findNewestFileInDirectory(__DIR__ . '/profilepicture');
    if ($folderCandidate !== null) {
        $candidates[] = $folderCandidate;
    }

    $candidates[] = __DIR__ . '/profile.jpg';
    $candidates[] = __DIR__ . '/profile.jpeg';
    $candidates[] = __DIR__ . '/profile.png';
    $candidates[] = $chatDir . '/profile.jpg';
    $candidates[] = $chatDir . '/profile.jpeg';
    $candidates[] = $chatDir . '/profile.png';

    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            return pathToUrl($candidate);
        }
    }

    return null;
}

function findOwnProfilePhoto(): ?string
{
    $folderCandidate = findNewestFileInDirectory(__DIR__ . '/myprofilepicture');
    if ($folderCandidate !== null) {
        return pathToUrl($folderCandidate);
    }

    $fallbacks = [
        __DIR__ . '/my-profile.jpg',
        __DIR__ . '/my-profile.jpeg',
        __DIR__ . '/my-profile.png',
    ];

    foreach ($fallbacks as $candidate) {
        if (is_file($candidate)) {
            return pathToUrl($candidate);
        }
    }

    return null;
}

function findBackgroundPhoto(): ?string
{
    $asset = findNewestFileInDirectory(__DIR__ . '/background');
    if ($asset === null) {
        return null;
    }

    return pathToUrl($asset);
}

function detectAttachmentKind(string $filename): string
{
    if (preg_match('/\.(jpe?g|png|gif|webp|bmp|heic|heif|avif)$/i', $filename) === 1) {
        return 'image';
    }

    if (
        preg_match('/\.(mp4|mov|m4v|3gp|webm)$/i', $filename) === 1
        || stripos($filename, '-VIDEO-') !== false
    ) {
        return 'video';
    }

    if (
        preg_match('/\.(opus|ogg|oga|mp3|m4a|aac|wav|webm)$/i', $filename) === 1
        || stripos($filename, '-AUDIO-') !== false
    ) {
        return 'audio';
    }

    return 'file';
}

function resolveAttachment(string $chatDir, ?string $filename): ?array
{
    if ($filename === null || $filename === '') {
        return null;
    }

    $absolutePath = $chatDir . DIRECTORY_SEPARATOR . $filename;
    if (!is_file($absolutePath)) {
        return null;
    }

    $url = pathToUrl($absolutePath);
    if ($url === null) {
        return null;
    }

    $kind = detectAttachmentKind($filename);

    return [
        'name' => $filename,
        'url' => $url,
        'kind' => $kind,
        'is_image' => $kind === 'image',
        'is_video' => $kind === 'video',
        'is_audio' => $kind === 'audio',
    ];
}

function readTextFileOrFallback(string $path, string $fallback): string
{
    if (!is_file($path) || !is_readable($path)) {
        return $fallback;
    }

    $contents = trim((string) file_get_contents($path));
    return $contents !== '' ? $contents : $fallback;
}

function readSvgFileOrFallback(string $path, string $fallback): string
{
    if (!is_file($path) || !is_readable($path)) {
        return $fallback;
    }

    $svg = trim((string) file_get_contents($path));
    if ($svg === '') {
        return $fallback;
    }

    $start = stripos($svg, '<svg');
    $end = stripos($svg, '</svg>');
    if ($start === false || $end === false || $end <= $start) {
        return $fallback;
    }

    $svg = substr($svg, $start, ($end - $start) + 6);
    $svg = preg_replace('/stroke\s*=\s*([\"\'])black\1/i', 'stroke="currentColor"', $svg) ?? $svg;
    $svg = preg_replace('/fill\s*=\s*([\"\'])black\1/i', 'fill="currentColor"', $svg) ?? $svg;
    $svg = preg_replace('/stroke\s*:\s*black/i', 'stroke:currentColor', $svg) ?? $svg;
    $svg = preg_replace('/fill\s*:\s*black/i', 'fill:currentColor', $svg) ?? $svg;

    return $svg;
}

$chatFile = findChatFile(__DIR__);
$messages = $chatFile ? parseChatMessages($chatFile) : [];
$chatDir = $chatFile ? dirname($chatFile) : __DIR__;

$senders = [];
foreach ($messages as $message) {
    if (is_string($message['sender']) && $message['sender'] !== '' && !in_array($message['sender'], $senders, true)) {
        $senders[] = $message['sender'];
    }
}

$folderTitle = folderDisplayName($chatDir);
$contactComparable = comparableName($folderTitle);
$contactName = $folderTitle;
foreach ($senders as $sender) {
    if (comparableName($sender) === $contactComparable) {
        $contactName = $sender;
        break;
    }
}

$ownerName = is_string($settings['owner_name']) && $settings['owner_name'] !== ''
    ? $settings['owner_name']
    : 'Ik';

if ($ownerName === 'Ik') {
    foreach ($senders as $sender) {
        if (comparableName($sender) !== comparableName($contactName)) {
            $ownerName = $sender;
            break;
        }
    }
}

$hasGroupSystemEvents = false;
foreach ($messages as $message) {
    if (($message['type'] ?? '') !== 'system') {
        continue;
    }
    $noticeType = detectWhatsAppNoticeType(
        is_string($message['sender'] ?? null) ? $message['sender'] : null,
        (string) ($message['text'] ?? ''),
        'system'
    );
    if ($noticeType === 'group_event') {
        $hasGroupSystemEvents = true;
        break;
    }
}
$isGroupChat = count($senders) > 2 || $hasGroupSystemEvents;

$headerTitle = is_string($settings['title']) && $settings['title'] !== '' ? $settings['title'] : $folderTitle;
$profilePhotoUrl = findProfilePhoto(is_string($settings['profile_photo']) ? $settings['profile_photo'] : null, $chatDir);
$myProfilePhotoUrl = findOwnProfilePhoto();
$backgroundPhotoUrl = findBackgroundPhoto();
$composerPlaceholder = readTextFileOrFallback(__DIR__ . '/composer_placeholder.txt', 'Chat from export');
$iconCheckSvg = readSvgFileOrFallback(
    __DIR__ . '/icons/check-check.svg',
    '<svg viewBox="0 0 16 11" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M1.3 6.1 3.8 8.6 8.2 3.6" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/><path d="M6.1 6.1 8.6 8.6 13 3.6" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>'
);
$iconMicSvg = readSvgFileOrFallback(
    __DIR__ . '/icons/mic.svg',
    '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 4.7a2.7 2.7 0 0 1 2.7 2.7v4.9a2.7 2.7 0 1 1-5.4 0V7.4A2.7 2.7 0 0 1 12 4.7Z" stroke="currentColor" stroke-width="1.8"/><path d="M7.7 11.8a4.3 4.3 0 0 0 8.6 0M12 18v1.5M9.5 21h5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>'
);
$iconCameraSvg = readSvgFileOrFallback(
    __DIR__ . '/icons/camera.svg',
    '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M8 7.2h1.8l1.1-1.4h2.2L14.2 7.2H16a2.8 2.8 0 0 1 2.8 2.8v5a2.8 2.8 0 0 1-2.8 2.8H8A2.8 2.8 0 0 1 5.2 15v-5A2.8 2.8 0 0 1 8 7.2Z" stroke="currentColor" stroke-width="1.7"/><circle cx="12" cy="12.5" r="3.1" stroke="currentColor" stroke-width="1.7"/></svg>'
);
$iconVideoSvg = readSvgFileOrFallback(
    __DIR__ . '/icons/video.svg',
    '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="m16 13 5.2 3.5a.5.5 0 0 0 .8-.4V7.9a.5.5 0 0 0-.8-.4L16 10.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><rect x="2" y="6" width="14" height="12" rx="2" stroke="currentColor" stroke-width="1.8"/></svg>'
);
$iconLockSvg = readSvgFileOrFallback(
    __DIR__ . '/icons/lock.svg',
    '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x="3.8" y="10.5" width="16.4" height="10.8" rx="2" stroke="currentColor" stroke-width="1.8"/><path d="M7.6 10.5V7.4a4.4 4.4 0 1 1 8.8 0v3.1" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>'
);
$iconCircleUserRoundSvg = readSvgFileOrFallback(
    __DIR__ . '/icons/circle-user-round.svg',
    '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M17.9 20a6 6 0 0 0-11.8 0" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><circle cx="12" cy="11" r="3.8" stroke="currentColor" stroke-width="1.8"/><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="1.8"/></svg>'
);
$iconMicFilledSvg = readSvgFileOrFallback(
    __DIR__ . '/icons/mic-filled.svg',
    '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 19v3M19 10v2a7 7 0 0 1-14 0v-2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M15 5v7a3 3 0 1 1-6 0V5a3 3 0 1 1 6 0Z" fill="currentColor" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>'
);
$iconUploadSvg = readSvgFileOrFallback(
    __DIR__ . '/icons/upload.svg',
    '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 16V4M12 4l-4 4M12 4l4 4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M4 16.5v1.2a2.3 2.3 0 0 0 2.3 2.3h11.4a2.3 2.3 0 0 0 2.3-2.3v-1.2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>'
);
$iconInfoSvg = readSvgFileOrFallback(
    __DIR__ . '/icons/info.svg',
    '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.8"/><path d="M12 10.2v6M12 7.8h.01" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>'
);
$iconSearchSvg = readSvgFileOrFallback(
    __DIR__ . '/icons/search.svg',
    '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="11" cy="11" r="6.8" stroke="currentColor" stroke-width="1.8"/><path d="m16.2 16.2 3.4 3.4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>'
);
$iconChevronUpSvg = readSvgFileOrFallback(
    __DIR__ . '/icons/chevron-up.svg',
    '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="m6.6 14.4 5.4-5.4 5.4 5.4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>'
);
$iconChevronDownSvg = readSvgFileOrFallback(
    __DIR__ . '/icons/chevron-down.svg',
    '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="m6.6 9.6 5.4 5.4 5.4-5.4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>'
);
$iconMicFilledSvg = preg_replace(
    '/<path\b(?![^>]*\bfill=)([^>]*)>/i',
    '<path fill="currentColor"$1>',
    $iconMicFilledSvg
) ?? $iconMicFilledSvg;
$messageAttachments = [];
$messageGalleryIndex = [];
$galleryItems = [];

foreach ($messages as $messageIndex => $message) {
    $attachment = resolveAttachment($chatDir, is_string($message['attachment']) ? $message['attachment'] : null);
    $messageAttachments[$messageIndex] = $attachment;

    if ($attachment === null || (!$attachment['is_image'] && !$attachment['is_video'])) {
        continue;
    }

    $messageGalleryIndex[$messageIndex] = count($galleryItems);
    $galleryItems[] = [
        'kind' => $attachment['kind'],
        'url' => $attachment['url'],
        'name' => $attachment['name'],
        'time' => formatTimeLabel(
            $message['datetime'] instanceof DateTimeImmutable ? $message['datetime'] : null,
            (string) $message['time_raw']
        ),
        'date' => formatDateChip(
            $message['datetime'] instanceof DateTimeImmutable ? $message['datetime'] : null,
            (string) $message['date_raw']
        ),
    ];
}

$galleryJson = json_encode(
    $galleryItems,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
);
$lastDateKey = null;
?>
<!doctype html>
<html lang="en" translate="yes">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
    <title><?= e($headerTitle) ?> - WhatsApp Memory</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div
        class="chat-bg<?= $backgroundPhotoUrl !== null ? ' has-custom-background' : '' ?>"
        aria-hidden="true"
        <?php if ($backgroundPhotoUrl !== null): ?>
            style="background-image: url('<?= e($backgroundPhotoUrl) ?>');"
        <?php endif; ?>
    ></div>

    <main class="app">
        <header class="topbar">
            <div class="avatar">
                <?php if ($profilePhotoUrl !== null): ?>
                    <img src="<?= e($profilePhotoUrl) ?>" alt="Profile photo">
                <?php else: ?>
                    <?= e(firstInitial($headerTitle)) ?>
                <?php endif; ?>
            </div>
            <div class="topbar-meta">
                <h1 class="topbar-title"><?= e($headerTitle) ?></h1>
                <div class="topbar-sub">WhatsApp Export Viewer</div>
            </div>
            <button type="button" class="topbar-action-btn" id="openExportHelpBtn" aria-label="How to export chat">
                <?= $iconInfoSvg ?>
            </button>
        </header>
        <section class="search-panel" id="searchPanel">
            <input
                type="search"
                class="search-input"
                id="searchInput"
                placeholder="Search chat..."
                autocomplete="off"
                spellcheck="false"
                aria-label="Search chat"
            >
            <span class="search-count" id="searchCount">0/0</span>
            <button type="button" class="search-nav-btn" id="searchPrevBtn" aria-label="Previous result">
                <?= $iconChevronUpSvg ?>
            </button>
            <button type="button" class="search-nav-btn" id="searchNextBtn" aria-label="Next result">
                <?= $iconChevronDownSvg ?>
            </button>
        </section>

        <section class="thread">
            <?php if (!$chatFile): ?>
                <div class="empty-state">
                    No <code>_chat.txt</code> found. Open upload with the upload icon below.
                </div>
            <?php elseif ($messages === []): ?>
                <div class="empty-state">
                    Chat file found, but no messages could be read.
                </div>
            <?php else: ?>
                <?php foreach ($messages as $messageIndex => $message): ?>
                    <?php
                    $dateKey = $message['datetime'] instanceof DateTimeImmutable
                        ? $message['datetime']->format('Y-m-d')
                        : (string) $message['date_raw'];
                    if ($dateKey !== $lastDateKey):
                        $lastDateKey = $dateKey;
                    ?>
                        <div class="date-chip">
                            <?= e(formatDateChip(
                                $message['datetime'] instanceof DateTimeImmutable ? $message['datetime'] : null,
                                (string) $message['date_raw']
                            )) ?>
                        </div>
                    <?php endif; ?>

                    <?php
                    $rowClass = 'system';
                    if ($message['type'] === 'message') {
                        $rowClass = samePerson((string) $message['sender'], $ownerName) ? 'outgoing' : 'incoming';
                    }
                    $attachment = $messageAttachments[$messageIndex] ?? null;
                    $galleryIndex = $messageGalleryIndex[$messageIndex] ?? null;
                    $bubbleClasses = ['bubble'];
                    if ($attachment !== null) {
                        $bubbleClasses[] = 'has-media';
                        $bubbleClasses[] = 'media-' . (string) $attachment['kind'];
                        if ((string) $message['text'] === '') {
                            $bubbleClasses[] = 'media-only';
                        }
                    }
                    $timeLabel = formatTimeLabel(
                        $message['datetime'] instanceof DateTimeImmutable ? $message['datetime'] : null,
                        (string) $message['time_raw']
                    );
                    $hasVideoAttachment = $attachment !== null && $attachment['is_video'];
                    $messageText = (string) $message['text'];
                    $renderTextBelowMedia = $attachment !== null
                        && ($attachment['is_image'] || $attachment['is_video'])
                        && $messageText !== '';
                    $showInlineMetaWithText = $message['type'] === 'message'
                        && !$hasVideoAttachment
                        && $messageText !== ''
                        && $message['edited'] !== true
                        && shouldInlineMetaWithText($messageText);
                    $senderName = is_string($message['sender']) ? trim($message['sender']) : '';
                    $showGroupSenderMeta = $isGroupChat
                        && $rowClass === 'incoming'
                        && $message['type'] === 'message'
                        && $senderName !== '';
                    $senderPalette = $showGroupSenderMeta ? senderColorPalette($senderName) : null;
                    $noticeType = detectWhatsAppNoticeType(
                        is_string($message['sender']) ? $message['sender'] : null,
                        $messageText,
                        is_string($message['type']) ? $message['type'] : null
                    );
                    $noticeIconSvg = $noticeType === 'encryption' ? $iconLockSvg : $iconCircleUserRoundSvg;
                    ?>
                    <?php if ($noticeType !== null && $attachment === null): ?>
                        <?php if ($noticeType === 'group_event'): ?>
                            <article class="msg-row system-event-row">
                                <div class="system-event-chip"><?= renderMessageText((string) $message['text']) ?></div>
                            </article>
                            <?php continue; ?>
                        <?php endif; ?>
                        <article class="msg-row system-notice">
                            <div class="notice-card notice-<?= e($noticeType) ?>">
                                <div class="notice-content">
                                    <span class="notice-icon" aria-hidden="true"><?= $noticeIconSvg ?></span>
                                    <div class="notice-text"><?= renderMessageText((string) $message['text']) ?></div>
                                </div>
                            </div>
                        </article>
                        <?php continue; ?>
                    <?php endif; ?>
                    <article class="msg-row <?= e($rowClass) ?>">
                        <div class="<?= e(implode(' ', $bubbleClasses)) ?>">
                            <?php if ($showGroupSenderMeta && is_array($senderPalette)): ?>
                                <div class="group-sender-meta" style="--sender-color: <?= e($senderPalette['text']) ?>; --sender-bg: <?= e($senderPalette['bg']) ?>;">
                                    <span class="group-sender-avatar" aria-hidden="true"><?= e(firstInitial($senderName)) ?></span>
                                    <span class="group-sender-name"><?= e($senderName) ?></span>
                                </div>
                            <?php endif; ?>

                            <?php if ($messageText !== '' && !$renderTextBelowMedia): ?>
                                <?php if ($showInlineMetaWithText): ?>
                                    <div class="bubble-text bubble-text-inline">
                                        <span class="bubble-inline-main"><?= renderMessageText($messageText) ?></span>
                                        <span class="bubble-meta-inline">
                                            <time><?= e($timeLabel) ?></time>
                                            <?php if ($rowClass === 'outgoing'): ?>
                                                <span class="status-checks" aria-hidden="true"><?= $iconCheckSvg ?></span>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                <?php else: ?>
                                    <div class="bubble-text"><?= renderMessageText($messageText) ?></div>
                                <?php endif; ?>
                            <?php endif; ?>

                            <?php if ($attachment !== null): ?>
                                <div class="attachment">
                                    <?php if ($attachment['is_image']): ?>
                                        <button
                                            type="button"
                                            class="image-button"
                                            data-gallery-index="<?= e((string) $galleryIndex) ?>"
                                            aria-label="Open image"
                                        >
                                            <img src="<?= e((string) $attachment['url']) ?>" alt="<?= e((string) $attachment['name']) ?>" loading="lazy">
                                        </button>
                                    <?php elseif ($attachment['is_video']): ?>
                                        <button
                                            type="button"
                                            class="video-preview-button"
                                            data-gallery-index="<?= e((string) $galleryIndex) ?>"
                                            aria-label="Open video"
                                        >
                                            <video
                                                class="video-preview-media"
                                                muted
                                                playsinline
                                                disablepictureinpicture
                                                disableremoteplayback
                                                preload="auto"
                                                data-preview-video
                                            >
                                                <source src="<?= e((string) $attachment['url']) ?>">
                                            </video>
                                            <span class="video-play-badge" aria-hidden="true"></span>
                                            <span class="video-preview-meta" aria-hidden="true">
                                                <span class="video-preview-left">
                                                    <span class="video-preview-icon"><?= $iconVideoSvg ?></span>
                                                    <span class="video-preview-duration" data-video-duration>0:00</span>
                                                </span>
                                                <span class="video-preview-right">
                                                    <span class="video-preview-time"><?= e($timeLabel) ?></span>
                                                    <?php if ($rowClass === 'outgoing'): ?>
                                                        <span class="video-preview-status"><?= $iconCheckSvg ?></span>
                                                    <?php endif; ?>
                                                </span>
                                            </span>
                                        </button>
                                    <?php elseif ($attachment['is_audio']): ?>
                                        <?php
                                        $isOutgoingAudio = $rowClass === 'outgoing';
                                        $audioCardClass = $isOutgoingAudio ? 'audio-card outgoing-audio' : 'audio-card incoming-audio';
                                        $voiceAvatarUrl = $isOutgoingAudio ? $myProfilePhotoUrl : $profilePhotoUrl;
                                        $voiceFallbackName = $isOutgoingAudio ? $ownerName : $contactName;
                                        $useGroupFallbackAvatar = $isGroupChat && !$isOutgoingAudio && $senderName !== '';
                                        if ($useGroupFallbackAvatar) {
                                            $voiceAvatarUrl = null;
                                            $voiceFallbackName = $senderName;
                                        }
                                        $voiceInitial = firstInitial($voiceFallbackName);
                                        $voiceSenderPalette = $useGroupFallbackAvatar ? senderColorPalette($senderName) : null;
                                        ?>
                                        <div class="<?= e($audioCardClass) ?>" data-audio-card<?php if ($useGroupFallbackAvatar): ?> data-audio-sender="<?= e($senderName) ?>"<?php endif; ?>>
                                            <div class="audio-avatar" aria-hidden="true"<?php if (is_array($voiceSenderPalette)): ?> style="--sender-color: <?= e($voiceSenderPalette['text']) ?>; --sender-bg: <?= e($voiceSenderPalette['bg']) ?>;"<?php endif; ?>>
                                                <?php if ($voiceAvatarUrl !== null): ?>
                                                    <img class="audio-avatar-image" src="<?= e($voiceAvatarUrl) ?>" alt="">
                                                <?php else: ?>
                                                    <span class="audio-avatar-fallback"><?= e($voiceInitial) ?></span>
                                                <?php endif; ?>
                                                <span class="audio-avatar-badge">
                                                    <?= $iconMicFilledSvg ?>
                                                </span>
                                            </div>
                                            <button type="button" class="audio-toggle" data-audio-toggle aria-label="Play voice message">
                                                <span class="audio-toggle-icon" aria-hidden="true"></span>
                                            </button>
                                            <div class="audio-track" data-audio-track>
                                                <div class="audio-waveform" data-audio-waveform aria-hidden="true"></div>
                                                <div class="audio-thumb" data-audio-thumb aria-hidden="true"></div>
                                                <input
                                                    class="audio-range"
                                                    data-audio-range
                                                    type="range"
                                                    min="0"
                                                    max="100"
                                                    value="0"
                                                    step="0.1"
                                                    aria-label="Scrub voice message"
                                                >
                                                <div class="audio-duration" data-audio-duration>0:00</div>
                                            </div>
                                            <audio class="audio-element" data-audio preload="none">
                                                <source src="<?= e((string) $attachment['url']) ?>">
                                            </audio>
                                        </div>
                                    <?php else: ?>
                                        <a class="file-link" href="<?= e((string) $attachment['url']) ?>" target="_blank" rel="noopener noreferrer">
                                            Download: <?= e((string) $attachment['name']) ?>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            <?php elseif (is_string($message['attachment']) && $message['attachment'] !== ''): ?>
                                <div class="omitted">File not found: <?= e($message['attachment']) ?></div>
                            <?php endif; ?>

                            <?php if ($renderTextBelowMedia): ?>
                                <?php if ($showInlineMetaWithText): ?>
                                    <div class="bubble-text bubble-text-inline">
                                        <span class="bubble-inline-main"><?= renderMessageText($messageText) ?></span>
                                        <span class="bubble-meta-inline">
                                            <time><?= e($timeLabel) ?></time>
                                            <?php if ($rowClass === 'outgoing'): ?>
                                                <span class="status-checks" aria-hidden="true"><?= $iconCheckSvg ?></span>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                <?php else: ?>
                                    <div class="bubble-text"><?= renderMessageText($messageText) ?></div>
                                <?php endif; ?>
                            <?php endif; ?>

                            <?php if (is_string($message['media_omitted']) && $message['media_omitted'] !== ''): ?>
                                <div class="omitted">
                                    <?= e(ucfirst((string) $message['media_omitted'])) ?> not included in export.
                                </div>
                            <?php endif; ?>

                            <?php if ($message['type'] === 'message' && !$hasVideoAttachment && !$showInlineMetaWithText): ?>
                                <div class="bubble-meta">
                                    <?php if ($message['edited'] === true): ?>
                                        <span class="edited">edited</span>
                                    <?php endif; ?>
                                    <time><?= e($timeLabel) ?></time>
                                    <?php if ($rowClass === 'outgoing'): ?>
                                        <span class="status-checks" aria-hidden="true">
                                            <?= $iconCheckSvg ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
    </main>
    <button id="scrollToBottomBtn" aria-label="Scroll to bottom">↓</button>
    <div id="timelineScroller" aria-label="Chat timeline navigation">
        <div id="timelineTrack"></div>
        <div id="timelineTicks" aria-hidden="true"></div>
        <div id="timelineThumb"></div>
        <div id="timelineDate"></div>
    </div>

    <div class="composer-shell" aria-hidden="true">
        <div class="composer">
            <button type="button" class="composer-icon composer-plus" id="jumpToUploadBtn" aria-label="Open upload">
                <?= $iconUploadSvg ?>
            </button>
            <div class="composer-input-wrap">
                <span class="composer-placeholder"><?= e($composerPlaceholder) ?></span>
            </div>
            <button type="button" class="composer-icon" id="toggleSearchBtn" aria-label="Search chat" aria-expanded="false">
                <?= $iconSearchSvg ?>
            </button>
        </div>
    </div>

    <aside class="viewer" id="viewer" aria-hidden="true">
        <div class="viewer-controls">
            <button type="button" id="viewerClose">Close</button>
        </div>
        <div class="viewer-stage" id="viewerStage">
            <img class="viewer-image" id="viewerImage" src="" alt="" hidden>
            <video
                class="viewer-video"
                id="viewerVideo"
                playsinline
                controls
                controlslist="nodownload noplaybackrate noremoteplayback"
                disablepictureinpicture
                disableremoteplayback
                preload="metadata"
                hidden
            ></video>
            <button type="button" class="viewer-video-play" id="viewerVideoPlay" aria-label="Play video"></button>
        </div>
        <div class="viewer-strip" id="viewerStrip"></div>
    </aside>

    <aside class="export-help-modal" id="exportHelpModal" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="exportHelpTitle">
        <div class="export-help-card">
            <h2 class="export-help-title" id="exportHelpTitle">Export a WhatsApp chat</h2>
            <ol class="export-help-steps">
                <li>Open the chat in WhatsApp.</li>
                <li>Tap the contact or group name at the top.</li>
                <li>Select <strong>Export Chat</strong>.</li>
                <li>Choose <strong>Include Media</strong> or <strong>Without Media</strong>.</li>
                <li>Save the ZIP and load it here with upload.</li>
            </ol>
            <p class="export-help-timeline">
                You can upload both <strong>personal (1-on-1)</strong> chats and <strong>group</strong> chats.
            </p>
            <p class="export-help-timeline">
                <strong>Upload button</strong>: in the bottom input bar, on the left. Use it to open the upload modal and load a new ZIP.
            </p>
            <p class="export-help-timeline">
                <strong>Search button</strong>: in the bottom input bar, on the right. Use it to open search, highlight matches, and jump to previous/next result.
            </p>
            <p class="export-help-timeline">
                <strong>Right-side timeline:</strong><br>
                <strong>dark tick</strong> = first day of the month,<br>
                <strong>light tick</strong> = first day of the week.
            </p>
            <button type="button" class="export-help-close" id="closeExportHelpBtn">Close</button>
        </div>
    </aside>

    <aside class="upload-modal" id="uploadModal" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="uploadModalTitle">
        <div class="upload-modal-card">
            <div class="upload-modal-head">
                <h2 class="upload-modal-title" id="uploadModalTitle">Load new chat</h2>
                <button type="button" class="upload-modal-close" id="closeUploadModalBtn" aria-label="Close upload window">Close</button>
            </div>
            <div class="upload-onboarding" id="uploadOnboarding" hidden>
                <h3 class="upload-onboarding-title">Export a WhatsApp chat</h3>
                <ol class="upload-onboarding-steps">
                    <li>Open the chat in WhatsApp.</li>
                    <li>Tap the contact or group name at the top.</li>
                    <li>Select <strong>Export Chat</strong>.</li>
                    <li>Choose <strong>Include Media</strong> or <strong>Without Media</strong>.</li>
                    <li>Save the ZIP and load it below.</li>
                </ol>
                <p class="export-help-timeline">
                    You can upload both <strong>personal (1-on-1)</strong> chats and <strong>group</strong> chats.
                </p>
                <p class="export-help-timeline">
                    <strong>Upload button</strong>: in the bottom input bar, on the left. Use it to open the upload modal and load a new ZIP.
                </p>
                <p class="export-help-timeline">
                    <strong>Search button</strong>: in the bottom input bar, on the right. Use it to open search, highlight matches, and jump to previous/next result.
                </p>
                <p class="export-help-timeline">
                    <strong>Right-side timeline:</strong><br>
                    <strong>dark tick</strong> = first day of the month,<br>
                    <strong>light tick</strong> = first day of the week.
                </p>
            </div>
            <div class="upload-card" id="zipUploadForm">
                <h2 class="upload-title">Open a local WhatsApp ZIP</h2>
                <p class="upload-note">
                    Your ZIP stays in your browser and is never sent to the server.
                    Profile photos are optional and are also kept locally.
                </p>
                <div class="upload-row">
                    <input
                        class="upload-input"
                        id="zipUploadInput"
                        name="chat_zip_local"
                        type="file"
                        accept=".zip,application/zip"
                        aria-label="Choose WhatsApp export ZIP"
                    >
                    <button class="upload-button" id="zipUploadButton" type="button">Load</button>
                </div>
                <div class="upload-row">
                    <label class="upload-label" for="myPhotoInput">Your photo</label>
                    <input
                        class="upload-input"
                        id="myPhotoInput"
                        name="my_photo_local"
                        type="file"
                        accept="image/*"
                        aria-label="Choose your profile photo"
                    >
                </div>
                <div class="upload-row">
                    <label class="upload-label" for="contactPhotoInput">Contact photo</label>
                    <input
                        class="upload-input"
                        id="contactPhotoInput"
                        name="contact_photo_local"
                        type="file"
                        accept="image/*"
                        aria-label="Choose contact profile photo"
                    >
                </div>
                <p class="upload-status" id="zipUploadStatus" hidden></p>
            </div>
        </div>
    </aside>

    <aside class="participant-modal" id="participantModal" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="participantModalTitle">
        <div class="participant-modal-card">
            <h2 class="participant-modal-title" id="participantModalTitle">Who is who</h2>
            <p class="participant-modal-note">Select names from the chat export so message direction is correct.</p>
            <div class="participant-row">
                <label class="participant-label" for="participantOwnerSelect">Your name in chat</label>
                <select id="participantOwnerSelect" class="participant-select"></select>
            </div>
            <div class="participant-row">
                <label class="participant-label" for="participantContactSelect">Other side / default incoming name</label>
                <select id="participantContactSelect" class="participant-select"></select>
            </div>
            <button type="button" class="participant-apply-btn" id="participantApplyBtn">Apply</button>
        </div>
    </aside>

    <script src="https://cdn.jsdelivr.net/npm/jszip@3.10.1/dist/jszip.min.js"></script>
    <script>
        (() => {
            let mediaGallery = <?= $galleryJson !== false ? $galleryJson : '[]' ?>;
            const viewer = document.getElementById('viewer');
            const viewerImage = document.getElementById('viewerImage');
            const viewerVideo = document.getElementById('viewerVideo');
            const viewerVideoPlay = document.getElementById('viewerVideoPlay');
            const viewerStage = document.getElementById('viewerStage');
            const viewerStrip = document.getElementById('viewerStrip');
            const closeButton = document.getElementById('viewerClose');
            const thread = document.querySelector('.thread');
            const uploadModal = document.getElementById('uploadModal');
            const closeUploadModalBtn = document.getElementById('closeUploadModalBtn');
            const uploadOnboarding = document.getElementById('uploadOnboarding');
            const exportHelpModal = document.getElementById('exportHelpModal');
            const openExportHelpBtn = document.getElementById('openExportHelpBtn');
            const closeExportHelpBtn = document.getElementById('closeExportHelpBtn');
            const toggleSearchBtn = document.getElementById('toggleSearchBtn');
            const searchPanel = document.getElementById('searchPanel');
            const searchInput = document.getElementById('searchInput');
            const searchCount = document.getElementById('searchCount');
            const searchPrevBtn = document.getElementById('searchPrevBtn');
            const searchNextBtn = document.getElementById('searchNextBtn');
            const jumpToUploadBtn = document.getElementById('jumpToUploadBtn');
            const scrollBtn = document.getElementById('scrollToBottomBtn');
            const timeline = document.getElementById('timelineScroller');
            const timelineTrack = document.getElementById('timelineTrack');
            const timelineTicks = document.getElementById('timelineTicks');
            const thumb = document.getElementById('timelineThumb');
            const timelineDate = document.getElementById('timelineDate');
            const topbarTitle = document.querySelector('.topbar-title');
            const topbarAvatar = document.querySelector('.topbar .avatar');
            const zipUploadInput = document.getElementById('zipUploadInput');
            const zipUploadButton = document.getElementById('zipUploadButton');
            const zipUploadStatus = document.getElementById('zipUploadStatus');
            const myPhotoInput = document.getElementById('myPhotoInput');
            const contactPhotoInput = document.getElementById('contactPhotoInput');
            const participantModal = document.getElementById('participantModal');
            const participantOwnerSelect = document.getElementById('participantOwnerSelect');
            const participantContactSelect = document.getElementById('participantContactSelect');
            const participantApplyBtn = document.getElementById('participantApplyBtn');

            const iconCheckSvg = <?= json_encode($iconCheckSvg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
            const iconVideoSvg = <?= json_encode($iconVideoSvg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
            const iconLockSvg = <?= json_encode($iconLockSvg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
            const iconCircleUserRoundSvg = <?= json_encode($iconCircleUserRoundSvg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
            const iconMicFilledSvg = <?= json_encode($iconMicFilledSvg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;

            const staticProfilePhotoUrl = <?= json_encode($profilePhotoUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
            const staticMyProfilePhotoUrl = <?= json_encode($myProfilePhotoUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
            const staticOwnerName = <?= json_encode($ownerName, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?> || 'Ik';
            const staticContactName = <?= json_encode($contactName, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?> || 'Contact';
            const staticIsGroupChat = <?= $isGroupChat ? 'true' : 'false' ?>;

            const MIN_ZOOM = 1;
            const MAX_ZOOM = 6;
            const PINCH_SENSITIVITY = 0.56;
            const SWIPE_DISTANCE_THRESHOLD = 52;
            const SWIPE_AXIS_RATIO = 1.2;
            const WHEEL_NAV_THRESHOLD = 34;
            const WHEEL_NAV_COOLDOWN_MS = 240;

            let activeAudio = null;
            const audioAnimationFrames = new WeakMap();
            let audioCardObserver = null;
            let currentMediaIndex = -1;
            let currentMediaKind = null;
            let galleryThumbButtons = [];
            let scale = 1;
            let panX = 0;
            let panY = 0;
            let isDragging = false;
            let startX = 0;
            let startY = 0;
            let startPanX = 0;
            let startPanY = 0;
            let initialPinchDistance = null;
            let initialScale = 1;
            let touchSwipeStartX = null;
            let touchSwipeStartY = null;
            let lastWheelNavigationAt = 0;
            let activeDynamicObjectUrls = new Set();
            let currentMessages = null;
            let baseProfilePhotoUrl = staticProfilePhotoUrl;
            let baseMyProfilePhotoUrl = staticMyProfilePhotoUrl;
            let customProfilePhotoUrl = null;
            let customMyProfilePhotoUrl = null;
            let customProfileObjectUrl = null;
            let customMyObjectUrl = null;
            let currentContext = {
                headerTitle: topbarTitle ? topbarTitle.textContent.trim() : 'Chat',
                ownerName: staticOwnerName,
                contactName: staticContactName,
                isGroupChat: staticIsGroupChat,
                senders: <?= json_encode($senders, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?> || [],
                profilePhotoUrl: staticProfilePhotoUrl,
                myProfilePhotoUrl: staticMyProfilePhotoUrl,
            };

            const stripControlChars = (value) => String(value)
                .replace(/\uFEFF/g, '')
                .replace(/[\u200E\u200F\u202A-\u202E\u2066-\u2069]/g, '');

            const normalizeLine = (line) => stripControlChars(String(line).replace(/[\r\n]+$/g, ''));

            const escapeHtml = (value) => String(value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');

            const normalizeForCompare = (value) => {
                const cleaned = stripControlChars(String(value).trim());
                const collapsed = cleaned
                    .replace(/[^\p{L}\p{N}]+/gu, ' ')
                    .replace(/\s+/g, ' ')
                    .trim();
                return collapsed.toLocaleLowerCase('nl-NL');
            };

            const samePerson = (left, right) => normalizeForCompare(left) === normalizeForCompare(right);

            const hashStringToHue = (value) => {
                const text = normalizeForCompare(value || '') || String(value || '');
                let hash = 0;
                for (let i = 0; i < text.length; i += 1) {
                    hash = ((hash << 5) - hash) + text.charCodeAt(i);
                    hash |= 0;
                }
                return Math.abs(hash) % 360;
            };

            const senderPaletteForName = (name) => {
                const hue = hashStringToHue(name);
                return {
                    text: `hsl(${hue} 68% 34%)`,
                    bg: `hsl(${hue} 78% 91%)`,
                };
            };

            const applySenderPalette = (node, senderName) => {
                if (!(node instanceof HTMLElement)) {
                    return;
                }
                const palette = senderPaletteForName(senderName);
                node.style.setProperty('--sender-color', palette.text);
                node.style.setProperty('--sender-bg', palette.bg);
            };

            const firstInitial = (value) => {
                const cleaned = String(value || '').trim();
                if (cleaned === '') {
                    return '?';
                }

                const match = cleaned.match(/[\p{L}\p{N}]/u);
                if (match) {
                    return match[0];
                }
                return cleaned.charAt(0);
            };

            let searchMatches = [];
            let searchActiveIndex = -1;
            let searchQuery = '';
            const searchTargetSelector = '.bubble-text, .notice-text, .omitted, .file-link, .date-chip';
            const PAGE_SCROLL_STORAGE_KEY = 'wa_export_viewer_scroll_y';
            let pendingScrollRestoreY = null;

            const clearSearchHighlights = () => {
                if (!thread) {
                    return;
                }

                thread.querySelectorAll('[data-search-source]').forEach((element) => {
                    const source = element.getAttribute('data-search-source');
                    if (source !== null) {
                        element.innerHTML = source;
                    }
                    element.removeAttribute('data-search-source');
                });
                searchMatches = [];
                searchActiveIndex = -1;
            };

            const updateSearchUi = () => {
                if (searchCount) {
                    const current = searchMatches.length === 0 ? 0 : searchActiveIndex + 1;
                    searchCount.textContent = `${current}/${searchMatches.length}`;
                }
                const disableNav = searchMatches.length === 0;
                if (searchPrevBtn) {
                    searchPrevBtn.disabled = disableNav;
                }
                if (searchNextBtn) {
                    searchNextBtn.disabled = disableNav;
                }
            };

            const setActiveSearchMatch = (index, smooth = true) => {
                if (searchMatches.length === 0) {
                    searchActiveIndex = -1;
                    updateSearchUi();
                    return;
                }

                const safeIndex = ((index % searchMatches.length) + searchMatches.length) % searchMatches.length;
                searchMatches.forEach((node, nodeIndex) => {
                    node.classList.toggle('is-active', nodeIndex === safeIndex);
                });
                searchActiveIndex = safeIndex;
                updateSearchUi();
                searchMatches[safeIndex].scrollIntoView({
                    block: 'center',
                    behavior: smooth ? 'smooth' : 'auto',
                });
            };

            const highlightElementMatches = (element, query) => {
                if (!element || query === '') {
                    return [];
                }

                if (!element.hasAttribute('data-search-source')) {
                    element.setAttribute('data-search-source', element.innerHTML);
                } else {
                    const source = element.getAttribute('data-search-source');
                    if (source !== null) {
                        element.innerHTML = source;
                    }
                }

                const walker = document.createTreeWalker(
                    element,
                    NodeFilter.SHOW_TEXT,
                    {
                        acceptNode(node) {
                            if (!node.nodeValue || node.nodeValue.trim() === '') {
                                return NodeFilter.FILTER_REJECT;
                            }
                            const parentTag = node.parentElement ? node.parentElement.tagName : '';
                            if (parentTag === 'MARK') {
                                return NodeFilter.FILTER_REJECT;
                            }
                            return NodeFilter.FILTER_ACCEPT;
                        },
                    }
                );

                const textNodes = [];
                let currentNode = walker.nextNode();
                while (currentNode) {
                    textNodes.push(currentNode);
                    currentNode = walker.nextNode();
                }

                const matches = [];
                textNodes.forEach((node) => {
                    const value = node.nodeValue || '';
                    const valueLower = value.toLocaleLowerCase('nl-NL');
                    const queryLower = query.toLocaleLowerCase('nl-NL');
                    let cursor = 0;
                    let found = valueLower.indexOf(queryLower, cursor);
                    if (found === -1) {
                        return;
                    }

                    const fragment = document.createDocumentFragment();
                    while (found !== -1) {
                        if (found > cursor) {
                            fragment.appendChild(document.createTextNode(value.slice(cursor, found)));
                        }

                        const end = found + query.length;
                        const mark = document.createElement('mark');
                        mark.className = 'search-highlight';
                        mark.textContent = value.slice(found, end);
                        fragment.appendChild(mark);
                        matches.push(mark);
                        cursor = end;
                        found = valueLower.indexOf(queryLower, cursor);
                    }

                    if (cursor < value.length) {
                        fragment.appendChild(document.createTextNode(value.slice(cursor)));
                    }
                    node.parentNode.replaceChild(fragment, node);
                });

                return matches;
            };

            const applySearch = (query, preserveIndex = false) => {
                searchQuery = String(query || '').trim();
                const previousIndex = searchActiveIndex;
                clearSearchHighlights();

                if (!thread || searchQuery === '') {
                    updateSearchUi();
                    return;
                }

                thread.querySelectorAll(searchTargetSelector).forEach((element) => {
                    const matches = highlightElementMatches(element, searchQuery);
                    if (matches.length > 0) {
                        searchMatches.push(...matches);
                    }
                });

                if (searchMatches.length > 0) {
                    const nextIndex = preserveIndex ? Math.min(Math.max(previousIndex, 0), searchMatches.length - 1) : 0;
                    setActiveSearchMatch(nextIndex, !preserveIndex);
                } else {
                    updateSearchUi();
                }
            };

            const navigateSearch = (direction) => {
                if (searchMatches.length === 0) {
                    return;
                }
                setActiveSearchMatch(searchActiveIndex + direction, true);
            };

            const reapplySearchAfterRender = () => {
                if (searchQuery !== '') {
                    applySearch(searchQuery, true);
                } else {
                    updateSearchUi();
                }
            };

            const savePageScrollPosition = () => {
                try {
                    localStorage.setItem(PAGE_SCROLL_STORAGE_KEY, String(Math.max(0, Math.round(window.scrollY))));
                } catch (error) {
                    // Ignore storage failures (private mode / restricted storage).
                }
            };

            const loadPendingScrollRestore = () => {
                try {
                    const rawValue = localStorage.getItem(PAGE_SCROLL_STORAGE_KEY);
                    if (rawValue === null) {
                        return;
                    }
                    const parsed = Number(rawValue);
                    if (Number.isFinite(parsed) && parsed >= 0) {
                        pendingScrollRestoreY = parsed;
                    }
                } catch (error) {
                    pendingScrollRestoreY = null;
                }
            };

            const restorePageScrollPosition = () => {
                if (pendingScrollRestoreY === null) {
                    return;
                }
                const maxScroll = getMaxScrollTop();
                const target = Math.max(0, pendingScrollRestoreY);

                // If content is not tall enough yet (for example while async ZIP restore is still rendering),
                // keep the pending value and retry after subsequent renders/resizes.
                if (target > maxScroll + 2) {
                    return;
                }

                window.scrollTo(0, clamp(target, 0, maxScroll));
                pendingScrollRestoreY = null;
            };

            const SCROLL_TO_BOTTOM_THRESHOLD = 180;
            let timelineAnchors = [];
            let timelineRafPending = false;
            let timelineDragging = false;
            let timelineDragHideTimer = null;

            const getMaxScrollTop = () => Math.max(0, document.documentElement.scrollHeight - window.innerHeight);

            const getStickyTopOffset = () => {
                const topbar = document.querySelector('.topbar');
                return (topbar ? topbar.offsetHeight : 0) + 10;
            };

            const clamp = (value, min, max) => Math.max(min, Math.min(max, value));

            const parseTimelineDateLabel = (label) => {
                const value = String(label || '').trim().toLowerCase();
                if (value === '') {
                    return null;
                }

                const months = {
                    jan: 0, feb: 1, mrt: 2, apr: 3, mei: 4, jun: 5,
                    jul: 6, aug: 7, sep: 8, okt: 9, nov: 10, dec: 11,
                };

                const shortDateMatch = value.match(/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{2,4})$/);
                if (shortDateMatch) {
                    const day = Number(shortDateMatch[1]);
                    const month = Number(shortDateMatch[2]) - 1;
                    let year = Number(shortDateMatch[3]);
                    if (year < 100) {
                        year += 2000;
                    }
                    const parsed = new Date(year, month, day);
                    if (!Number.isNaN(parsed.getTime())) {
                        return parsed;
                    }
                }

                const dutchMatch = value.match(/^(\d{1,2})\s+([a-z]{3})\s+(\d{4})$/);
                if (!dutchMatch) {
                    return null;
                }

                const day = Number(dutchMatch[1]);
                const month = months[dutchMatch[2]];
                const year = Number(dutchMatch[3]);
                if (!Number.isFinite(day) || !Number.isFinite(year) || month === undefined) {
                    return null;
                }

                const parsed = new Date(year, month, day);
                if (Number.isNaN(parsed.getTime())) {
                    return null;
                }

                if (parsed.getDate() !== day || parsed.getMonth() !== month || parsed.getFullYear() !== year) {
                    return null;
                }
                return parsed;
            };

            const getIsoWeekKey = (date) => {
                const utcDate = new Date(Date.UTC(date.getFullYear(), date.getMonth(), date.getDate()));
                const day = utcDate.getUTCDay() || 7;
                utcDate.setUTCDate(utcDate.getUTCDate() + 4 - day);
                const yearStart = new Date(Date.UTC(utcDate.getUTCFullYear(), 0, 1));
                const week = Math.ceil((((utcDate - yearStart) / 86400000) + 1) / 7);
                return `${utcDate.getUTCFullYear()}-${String(week).padStart(2, '0')}`;
            };

            const findClosestTimelineAnchor = (targetTop) => {
                if (timelineAnchors.length === 0) {
                    return null;
                }

                let closest = timelineAnchors[0];
                let closestDistance = Math.abs(closest.top - targetTop);
                for (let i = 1; i < timelineAnchors.length; i += 1) {
                    const candidate = timelineAnchors[i];
                    const distance = Math.abs(candidate.top - targetTop);
                    if (distance < closestDistance) {
                        closestDistance = distance;
                        closest = candidate;
                    }
                }

                return closest;
            };

            const showTimelineDateBubble = () => {
                if (!timelineDate) {
                    return;
                }

                timelineDate.classList.add('is-visible');
                if (timelineDragHideTimer) {
                    window.clearTimeout(timelineDragHideTimer);
                    timelineDragHideTimer = null;
                }
            };

            const hideTimelineDateBubbleSoon = () => {
                if (!timelineDate) {
                    return;
                }

                if (timelineDragHideTimer) {
                    window.clearTimeout(timelineDragHideTimer);
                }
                timelineDragHideTimer = window.setTimeout(() => {
                    timelineDate.classList.remove('is-visible');
                    timelineDragHideTimer = null;
                }, 280);
            };

            const getTimelineTrackMetrics = () => {
                if (!timeline || !timelineTrack) {
                    return null;
                }

                const trackRect = timelineTrack.getBoundingClientRect();
                const timelineRect = timeline.getBoundingClientRect();
                if (trackRect.height <= 0 || timelineRect.height <= 0) {
                    return null;
                }

                return {
                    top: trackRect.top - timelineRect.top,
                    height: trackRect.height,
                    viewportTop: trackRect.top,
                };
            };

            const refreshTimelineTickMarks = () => {
                if (!timelineTicks) {
                    return;
                }

                timelineTicks.innerHTML = '';
                if (timelineAnchors.length < 2) {
                    return;
                }

                const metrics = getTimelineTrackMetrics();
                if (!metrics) {
                    return;
                }

                const maxScroll = getMaxScrollTop();
                const seenMonths = new Set();
                const seenWeeks = new Set();
                const tickItems = [];

                timelineAnchors.forEach((anchor) => {
                    if (!(anchor.date instanceof Date)) {
                        return;
                    }

                    const monthKey = `${anchor.date.getFullYear()}-${String(anchor.date.getMonth() + 1).padStart(2, '0')}`;
                    if (!seenMonths.has(monthKey)) {
                        seenMonths.add(monthKey);
                        tickItems.push({ anchor, type: 'major' });
                        return;
                    }

                    const weekKey = getIsoWeekKey(anchor.date);
                    if (!seenWeeks.has(weekKey)) {
                        seenWeeks.add(weekKey);
                        tickItems.push({ anchor, type: 'minor' });
                    }
                });

                // Fallback if dates are unparseable: keep old behavior (one per day chip).
                if (tickItems.length === 0) {
                    timelineAnchors.forEach((anchor, index) => {
                        tickItems.push({
                            anchor,
                            type: (index === 0 || index === timelineAnchors.length - 1) ? 'major' : 'minor',
                        });
                    });
                }

                tickItems.forEach(({ anchor, type }) => {
                    const tick = document.createElement('span');
                    tick.className = 'timeline-tick';
                    if (type === 'major') {
                        tick.classList.add('is-major');
                    }
                    const ratio = maxScroll > 0 ? clamp(anchor.top / maxScroll, 0, 1) : 0;
                    tick.dataset.ratio = String(ratio);
                    tick.style.top = `${metrics.top + (ratio * metrics.height)}px`;
                    timelineTicks.appendChild(tick);
                });
            };

            const rebuildTimelineAnchors = () => {
                if (!thread || !timeline) {
                    timelineAnchors = [];
                    return;
                }

                const chips = Array.from(thread.querySelectorAll('.date-chip'));
                const stickyOffset = getStickyTopOffset();
                timelineAnchors = chips
                    .map((chip) => {
                        const label = chip.textContent ? chip.textContent.trim() : '';
                        const absoluteTop = window.scrollY + chip.getBoundingClientRect().top - stickyOffset;
                        const parsedDate = parseTimelineDateLabel(label);
                        return {
                            label,
                            top: Math.max(0, absoluteTop),
                            date: parsedDate,
                        };
                    })
                    .filter((item) => item.label !== '');

                timeline.classList.toggle('is-disabled', timelineAnchors.length < 2);
                refreshTimelineTickMarks();
            };

            const syncScrollControls = () => {
                const maxScroll = getMaxScrollTop();
                const scrollTop = window.scrollY;

                if (scrollBtn) {
                    const isVisible = scrollTop < (maxScroll - SCROLL_TO_BOTTOM_THRESHOLD);
                    scrollBtn.classList.toggle('is-visible', isVisible);
                }

                if (!timeline || !thumb || !timelineDate) {
                    return;
                }

                const metrics = getTimelineTrackMetrics();
                if (!metrics) {
                    return;
                }

                const maxThumbTop = Math.max(0, metrics.height - thumb.clientHeight);
                const ratio = maxScroll > 0 ? clamp(scrollTop / maxScroll, 0, 1) : 0;
                const thumbTop = metrics.top + (ratio * maxThumbTop);
                thumb.style.top = `${thumbTop}px`;

                const anchor = findClosestTimelineAnchor(scrollTop + getStickyTopOffset());
                if (anchor) {
                    timelineDate.textContent = anchor.label;
                    timelineDate.style.top = `${thumbTop}px`;
                }
            };

            const queueSyncScrollControls = () => {
                if (timelineRafPending) {
                    return;
                }

                timelineRafPending = true;
                window.requestAnimationFrame(() => {
                    timelineRafPending = false;
                    syncScrollControls();
                });
            };

            const timelineRatioFromPointer = (clientY) => {
                if (!timelineTrack) {
                    return 0;
                }

                const rect = timelineTrack.getBoundingClientRect();
                if (rect.height <= 0) {
                    return 0;
                }
                return clamp((clientY - rect.top) / rect.height, 0, 1);
            };

            const scrollTimelineToRatio = (ratio, behavior = 'auto', snapToDate = true) => {
                const maxScroll = getMaxScrollTop();
                const rawTarget = clamp(ratio, 0, 1) * maxScroll;
                let targetTop = rawTarget;

                if (snapToDate && ratio > 0.03 && ratio < 0.97) {
                    const snapAnchor = findClosestTimelineAnchor(rawTarget);
                    if (snapAnchor) {
                        targetTop = snapAnchor.top;
                    }
                }

                window.scrollTo({
                    top: clamp(targetTop, 0, maxScroll),
                    behavior,
                });
            };

            const initializeTimelineControls = () => {
                rebuildTimelineAnchors();
                queueSyncScrollControls();
            };

            const setUploadStatus = (message, isError = false) => {
                if (!zipUploadStatus) {
                    return;
                }

                const text = String(message || '').trim();
                if (text === '') {
                    zipUploadStatus.hidden = true;
                    zipUploadStatus.textContent = '';
                    zipUploadStatus.classList.remove('is-error');
                    return;
                }

                zipUploadStatus.hidden = false;
                zipUploadStatus.textContent = text;
                zipUploadStatus.classList.toggle('is-error', isError);
            };

            const UPLOAD_ONBOARDING_SEEN_KEY = 'wa_export_viewer_seen_upload_onboarding';
            const PARTICIPANT_PREFS_COOKIE = 'wa_export_participants_v1';

            const openUploadModal = (showOnboarding = false) => {
                if (!uploadModal) {
                    return;
                }

                uploadModal.setAttribute('aria-hidden', 'false');
                if (uploadOnboarding) {
                    uploadOnboarding.hidden = !showOnboarding;
                }
            };

            const closeUploadModal = () => {
                if (!uploadModal) {
                    return;
                }
                uploadModal.setAttribute('aria-hidden', 'true');
            };

            const openParticipantModal = () => {
                if (!participantModal) {
                    return;
                }
                participantModal.setAttribute('aria-hidden', 'false');
            };

            const closeParticipantModal = () => {
                if (!participantModal) {
                    return;
                }
                participantModal.setAttribute('aria-hidden', 'true');
            };

            const uniqueSenderList = (senders) => {
                const seen = new Set();
                const result = [];
                (Array.isArray(senders) ? senders : []).forEach((name) => {
                    const value = String(name || '').trim();
                    if (value === '') {
                        return;
                    }
                    const key = normalizeForCompare(value);
                    if (seen.has(key)) {
                        return;
                    }
                    seen.add(key);
                    result.push(value);
                });
                return result;
            };

            const readCookieValue = (name) => {
                const prefix = `${name}=`;
                const parts = String(document.cookie || '').split(';');
                for (const part of parts) {
                    const cookie = part.trim();
                    if (cookie.startsWith(prefix)) {
                        return decodeURIComponent(cookie.slice(prefix.length));
                    }
                }
                return '';
            };

            const writeCookieValue = (name, value, days = 365) => {
                const expires = new Date(Date.now() + (days * 24 * 60 * 60 * 1000)).toUTCString();
                document.cookie = `${name}=${encodeURIComponent(value)}; expires=${expires}; path=/; SameSite=Lax`;
            };

            const participantContextKey = (headerTitle, senders) => {
                const titleKey = normalizeForCompare(headerTitle || '');
                const senderKey = uniqueSenderList(senders)
                    .map((name) => normalizeForCompare(name))
                    .filter((name) => name !== '')
                    .sort()
                    .join('|');
                return `${titleKey}::${senderKey}`;
            };

            const readParticipantPrefs = () => {
                const raw = readCookieValue(PARTICIPANT_PREFS_COOKIE);
                if (!raw) {
                    return {};
                }
                try {
                    const parsed = JSON.parse(raw);
                    return parsed && typeof parsed === 'object' ? parsed : {};
                } catch (error) {
                    return {};
                }
            };

            const writeParticipantPrefs = (prefs) => {
                writeCookieValue(PARTICIPANT_PREFS_COOKIE, JSON.stringify(prefs), 365);
            };

            const resolveSenderNameFromList = (senders, candidate) => {
                const value = String(candidate || '').trim();
                if (value === '') {
                    return '';
                }
                const found = uniqueSenderList(senders).find((name) => samePerson(name, value));
                return found || '';
            };

            const getSavedParticipantMapping = (headerTitle, senders) => {
                const key = participantContextKey(headerTitle, senders);
                const prefs = readParticipantPrefs();
                const item = prefs[key];
                if (!item || typeof item !== 'object') {
                    return null;
                }
                const ownerName = resolveSenderNameFromList(senders, item.ownerName);
                if (ownerName === '') {
                    return null;
                }
                const contactName = resolveSenderNameFromList(senders, item.contactName) || String(item.contactName || '').trim();
                return { ownerName, contactName };
            };

            const saveParticipantMapping = (headerTitle, senders, ownerName, contactName) => {
                const owner = resolveSenderNameFromList(senders, ownerName) || String(ownerName || '').trim();
                if (owner === '') {
                    return;
                }
                const contact = resolveSenderNameFromList(senders, contactName) || String(contactName || '').trim();
                const key = participantContextKey(headerTitle, senders);
                const prefs = readParticipantPrefs();
                prefs[key] = { ownerName: owner, contactName: contact };
                writeParticipantPrefs(prefs);
            };

            const fillParticipantSelect = (select, options, selectedValue) => {
                if (!(select instanceof HTMLSelectElement)) {
                    return;
                }
                select.innerHTML = '';
                options.forEach((name) => {
                    const option = document.createElement('option');
                    option.value = name;
                    option.textContent = name;
                    if (name === selectedValue) {
                        option.selected = true;
                    }
                    select.appendChild(option);
                });
                if (select.options.length > 0 && select.selectedIndex < 0) {
                    select.selectedIndex = 0;
                }
            };

            const openParticipantPickerForCurrentChat = () => {
                const senders = uniqueSenderList(currentContext.senders);
                if (senders.length === 0) {
                    return;
                }

                const saved = getSavedParticipantMapping(currentContext.headerTitle, senders);
                const ownerSeed = saved ? saved.ownerName : currentContext.ownerName;
                const contactSeed = saved ? saved.contactName : currentContext.contactName;

                const ownerDefault = senders.find((name) => samePerson(name, ownerSeed)) || senders[0];
                let contactDefault = senders.find((name) => samePerson(name, contactSeed)) || senders.find((name) => !samePerson(name, ownerDefault)) || senders[0];

                fillParticipantSelect(participantOwnerSelect, senders, ownerDefault);
                fillParticipantSelect(participantContactSelect, senders, contactDefault);

                if (participantOwnerSelect && participantContactSelect && senders.length > 1) {
                    const ensureDifferent = () => {
                        if (participantOwnerSelect.value !== participantContactSelect.value) {
                            return;
                        }
                        const fallback = senders.find((name) => name !== participantOwnerSelect.value);
                        if (fallback) {
                            participantContactSelect.value = fallback;
                        }
                    };
                    ensureDifferent();
                }

                openParticipantModal();
            };

            const shouldShowUploadOnboarding = () => {
                try {
                    return localStorage.getItem(UPLOAD_ONBOARDING_SEEN_KEY) === null;
                } catch (error) {
                    return true;
                }
            };

            const markUploadOnboardingSeen = () => {
                try {
                    localStorage.setItem(UPLOAD_ONBOARDING_SEEN_KEY, '1');
                } catch (error) {
                    // Ignore storage write failures.
                }
            };

            const setUploadBusy = (busy) => {
                if (zipUploadButton) {
                    zipUploadButton.disabled = busy;
                    zipUploadButton.textContent = busy ? 'Loading...' : 'Load';
                }
                if (zipUploadInput) {
                    zipUploadInput.disabled = busy;
                }
                if (myPhotoInput) {
                    myPhotoInput.disabled = busy;
                }
                if (contactPhotoInput) {
                    contactPhotoInput.disabled = busy;
                }
            };

            const mimeTypeForFilename = (filename) => {
                const name = String(filename || '').toLowerCase();
                if (name.endsWith('.jpg') || name.endsWith('.jpeg')) {
                    return 'image/jpeg';
                }
                if (name.endsWith('.png')) {
                    return 'image/png';
                }
                if (name.endsWith('.gif')) {
                    return 'image/gif';
                }
                if (name.endsWith('.webp')) {
                    return 'image/webp';
                }
                if (name.endsWith('.bmp')) {
                    return 'image/bmp';
                }
                if (name.endsWith('.heic')) {
                    return 'image/heic';
                }
                if (name.endsWith('.heif')) {
                    return 'image/heif';
                }
                if (name.endsWith('.avif')) {
                    return 'image/avif';
                }
                if (name.endsWith('.mp4')) {
                    return 'video/mp4';
                }
                if (name.endsWith('.mov')) {
                    return 'video/quicktime';
                }
                if (name.endsWith('.m4v')) {
                    return 'video/x-m4v';
                }
                if (name.endsWith('.3gp')) {
                    return 'video/3gpp';
                }
                if (name.endsWith('.webm')) {
                    return 'video/webm';
                }
                if (name.endsWith('.opus')) {
                    return 'audio/ogg; codecs=opus';
                }
                if (name.endsWith('.ogg') || name.endsWith('.oga')) {
                    return 'audio/ogg';
                }
                if (name.endsWith('.mp3')) {
                    return 'audio/mpeg';
                }
                if (name.endsWith('.m4a')) {
                    return 'audio/mp4';
                }
                if (name.endsWith('.aac')) {
                    return 'audio/aac';
                }
                if (name.endsWith('.wav')) {
                    return 'audio/wav';
                }
                return '';
            };

            const renderTopbarAvatar = (photoUrl) => {
                if (!topbarAvatar) {
                    return;
                }

                topbarAvatar.innerHTML = '';
                if (photoUrl) {
                    const image = document.createElement('img');
                    image.src = photoUrl;
                    image.alt = 'Profile photo';
                    topbarAvatar.appendChild(image);
                    return;
                }

                topbarAvatar.textContent = firstInitial(currentContext.headerTitle || 'Chat');
            };

            const updateAudioAvatarsInDom = () => {
                document.querySelectorAll('.audio-card').forEach((card) => {
                    const isOutgoing = card.classList.contains('outgoing-audio');
                    const avatar = card.querySelector('.audio-avatar');
                    if (!avatar) {
                        return;
                    }

                    avatar.innerHTML = '';
                    const senderName = String(card.getAttribute('data-audio-sender') || '').trim();
                    const useGroupFallback = !isOutgoing && currentContext.isGroupChat && senderName !== '';
                    const url = useGroupFallback
                        ? null
                        : (isOutgoing ? currentContext.myProfilePhotoUrl : currentContext.profilePhotoUrl);
                    const fallback = useGroupFallback
                        ? senderName
                        : (isOutgoing ? currentContext.ownerName : currentContext.contactName);

                    if (useGroupFallback) {
                        applySenderPalette(avatar, senderName);
                    } else {
                        avatar.style.removeProperty('--sender-color');
                        avatar.style.removeProperty('--sender-bg');
                    }

                    if (url) {
                        const image = document.createElement('img');
                        image.className = 'audio-avatar-image';
                        image.src = url;
                        image.alt = '';
                        avatar.appendChild(image);
                    } else {
                        const fallbackNode = document.createElement('span');
                        fallbackNode.className = 'audio-avatar-fallback';
                        fallbackNode.textContent = firstInitial(fallback || '');
                        avatar.appendChild(fallbackNode);
                    }

                    const badge = document.createElement('span');
                    badge.className = 'audio-avatar-badge';
                    badge.innerHTML = iconMicFilledSvg;
                    avatar.appendChild(badge);
                });
            };

            const parseDateTime = (datePart, timePart) => {
                const dateMatch = String(datePart).trim().match(/^(\d{1,2})([\/\-.])(\d{1,2})\2(\d{2,4})$/);
                if (!dateMatch) {
                    return null;
                }

                const day = Number(dateMatch[1]);
                const month = Number(dateMatch[3]);
                let year = Number(dateMatch[4]);
                if (year < 100) {
                    year += 2000;
                }

                const timeText = String(timePart).replace(/\u202F/g, ' ').trim();
                const timeMatch = timeText.match(/^(\d{1,2}):(\d{2})(?::(\d{2}))?(?:\s*([AaPp][Mm]))?$/);
                if (!timeMatch) {
                    return null;
                }

                let hour = Number(timeMatch[1]);
                const minute = Number(timeMatch[2]);
                const second = Number(timeMatch[3] || 0);
                const meridiem = (timeMatch[4] || '').toUpperCase();

                if (meridiem === 'PM' && hour < 12) {
                    hour += 12;
                }
                if (meridiem === 'AM' && hour === 12) {
                    hour = 0;
                }

                const parsed = new Date(year, month - 1, day, hour, minute, second, 0);
                if (Number.isNaN(parsed.getTime())) {
                    return null;
                }

                if (
                    parsed.getFullYear() !== year
                    || parsed.getMonth() !== month - 1
                    || parsed.getDate() !== day
                ) {
                    return null;
                }

                return parsed;
            };

            const matchChatLine = (line) => {
                const patterns = [
                    /^\[(\d{1,2}[\/\-.]\d{1,2}[\/\-.]\d{2,4}),\s(.+?)\]\s(.*)$/u,
                    /^(\d{1,2}[\/\-.]\d{1,2}[\/\-.]\d{2,4}),\s(.+?)\s-\s(.*)$/u,
                ];

                for (const pattern of patterns) {
                    const match = line.match(pattern);
                    if (match) {
                        return {
                            dateRaw: match[1].trim(),
                            timeRaw: String(match[2]).replace(/\u202F/g, ' ').trim(),
                            payload: match[3].trim(),
                        };
                    }
                }

                return null;
            };

            const parseChatMessagesFromText = (chatText) => {
                const lines = String(chatText).replace(/\r\n?/g, '\n').split('\n');
                const staged = [];
                let current = null;

                for (const rawLine of lines) {
                    const line = normalizeLine(rawLine);
                    const matched = matchChatLine(line);

                    if (matched) {
                        if (current) {
                            staged.push(current);
                        }

                        let sender = null;
                        let text = matched.payload;
                        let type = 'system';
                        const payloadMatch = matched.payload.match(/^([^:]+):\s?(.*)$/u);
                        if (payloadMatch) {
                            sender = stripControlChars(payloadMatch[1]).trim();
                            text = payloadMatch[2];
                            type = 'message';
                        }

                        current = {
                            date_raw: matched.dateRaw,
                            time_raw: matched.timeRaw,
                            datetime: parseDateTime(matched.dateRaw, matched.timeRaw),
                            sender,
                            text: stripControlChars(text).trim(),
                            type,
                        };
                        continue;
                    }

                    if (!current) {
                        continue;
                    }

                    const continuation = stripControlChars(line).trim();
                    if (continuation === '' && current.text === '') {
                        continue;
                    }

                    current.text = current.text === ''
                        ? continuation
                        : `${current.text}\n${continuation}`;
                }

                if (current) {
                    staged.push(current);
                }

                const normalized = [];
                for (const message of staged) {
                    let text = String(message.text || '');
                    let attachment = null;
                    let mediaOmitted = null;
                    let edited = false;

                    const attachedTagMatch = text.match(/<attached:\s*([^>]+)>/iu);
                    if (attachedTagMatch) {
                        attachment = attachedTagMatch[1].trim();
                        text = text.replace(/<attached:\s*[^>]+>/iu, '').trim();
                    }

                    if (!attachment) {
                        const inlineAttachmentMatch = text.match(
                            /^\u200E?([^<>\r\n]+?\.[A-Za-z0-9]{2,8})(?:\s+\((?:file attached|bestand bijgevoegd|attached)\))?$/iu
                        );
                        if (inlineAttachmentMatch) {
                            attachment = inlineAttachmentMatch[1].trim();
                            text = '';
                        }
                    }

                    if (!attachment) {
                        const downloadPattern = text.match(/^Download:\s*(.+)$/iu);
                        if (downloadPattern) {
                            attachment = downloadPattern[1].trim();
                            text = '';
                        }
                    }

                    const omittedMatch = text.match(/\b(image|video|audio|sticker|document)\s+omitted\b/iu);
                    if (omittedMatch) {
                        mediaOmitted = omittedMatch[1].toLowerCase();
                        text = text.replace(/\b(image|video|audio|sticker|document)\s+omitted\b/iu, '').trim();
                    }

                    if (/<this message was edited>/iu.test(text) || /<dit bericht is bewerkt>/iu.test(text)) {
                        edited = true;
                        text = text
                            .replace(/<this message was edited>/giu, '')
                            .replace(/<dit bericht is bewerkt>/giu, '')
                            .trim();
                    }

                    const normalizedMessage = {
                        ...message,
                        text,
                        attachment,
                        media_omitted: mediaOmitted,
                        edited,
                        resolvedAttachment: null,
                    };

                    if (
                        normalizedMessage.type === 'message'
                        && normalizedMessage.text === ''
                        && !normalizedMessage.attachment
                        && !normalizedMessage.media_omitted
                        && normalizedMessage.edited === false
                    ) {
                        continue;
                    }

                    normalized.push(normalizedMessage);
                }

                return normalized;
            };

            const formatDateChip = (dateTime, fallback) => {
                if (!(dateTime instanceof Date)) {
                    return String(fallback || '');
                }

                const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
                const month = months[dateTime.getMonth()] || String(dateTime.getMonth() + 1);
                return `${dateTime.getDate()} ${month} ${dateTime.getFullYear()}`;
            };

            const formatTimeLabel = (dateTime, fallback) => {
                if (!(dateTime instanceof Date)) {
                    return String(fallback || '');
                }

                const hours = String(dateTime.getHours()).padStart(2, '0');
                const minutes = String(dateTime.getMinutes()).padStart(2, '0');
                return `${hours}:${minutes}`;
            };

            const dateKeyForMessage = (dateTime, dateRaw) => {
                if (!(dateTime instanceof Date)) {
                    return String(dateRaw || '');
                }
                const year = dateTime.getFullYear();
                const month = String(dateTime.getMonth() + 1).padStart(2, '0');
                const day = String(dateTime.getDate()).padStart(2, '0');
                return `${year}-${month}-${day}`;
            };

            const renderMessageText = (text) => {
                if (String(text) === '') {
                    return '';
                }

                const escaped = escapeHtml(text);
                const linked = escaped.replace(
                    /(https?:\/\/[^\s<]+)/giu,
                    (url) => `<a href="${url}" target="_blank" rel="noopener noreferrer">${url}</a>`
                );
                return linked.replace(/\n/g, '<br>');
            };

            const shouldInlineMetaWithText = (text, maxChars = 30) => {
                const normalized = stripControlChars(String(text))
                    .replace(/\r\n?/g, '\n')
                    .trim();

                if (normalized === '' || normalized.includes('\n')) {
                    return false;
                }
                if (/https?:\/\//iu.test(normalized)) {
                    return false;
                }

                return Array.from(normalized).length <= maxChars;
            };

            const detectWhatsAppNoticeType = (sender, text, messageType = null) => {
                const normalizedText = normalizeForCompare(text);
                if (normalizedText === '') {
                    return messageType === 'system' ? 'system' : null;
                }

                const isEncryptionNotice = normalizedText.includes('end to end encrypted')
                    || normalizedText.includes('end to end versleuteld')
                    || (normalizedText.includes('messages and calls') && normalizedText.includes('only people in this chat'));
                if (isEncryptionNotice) {
                    return 'encryption';
                }

                const isSecurityCodeEn = normalizedText.includes('security code')
                    && normalizedText.includes('changed');
                const isSecurityCodeNl = normalizedText.includes('beveiligingscode')
                    && (normalizedText.includes('gewijzigd') || normalizedText.includes('veranderd'));
                if (isSecurityCodeEn || isSecurityCodeNl) {
                    return 'number_change';
                }

                const normalizedSender = normalizeForCompare(sender || '');
                const looksLikeSystemSender = normalizedSender === ''
                    || normalizedSender.includes('system')
                    || normalizedSender.includes('whatsapp');
                if (looksLikeSystemSender) {
                    const isNumberChangeEn = normalizedText.includes('changed')
                        && normalizedText.includes('phone number')
                        && normalizedText.includes('new number');
                    const isNumberChangeNl = normalizedText.includes('nummer')
                        && (normalizedText.includes('gewijzigd') || normalizedText.includes('veranderd'))
                        && normalizedText.includes('nieuw');

                    if (isNumberChangeEn || isNumberChangeNl) {
                        return 'number_change';
                    }
                }

                const groupEventTokens = [
                    'added you',
                    'added ',
                    ' added ',
                    'removed you',
                    'removed ',
                    ' removed ',
                    ' left',
                    ' joined',
                    'created group',
                    'created this group',
                    'changed the group',
                    'changed this group',
                    'you deleted this message',
                    'deleted this message',
                    'this message was deleted',
                    'changed subject',
                    'changed description',
                    'changed the icon',
                    'made you an admin',
                    'made this group',
                    'group invite',
                    'invite link',
                    'disappearing messages',
                    ' verdwijnende berichten',
                    'heeft toegevoegd',
                    'is toegevoegd',
                    'heeft verwijderd',
                    'heeft de groeps',
                    'heeft het groeps',
                    'heeft de groep',
                    'heeft deze groep',
                    'heeft onderwerp',
                    'heeft beschrijving',
                    'heeft pictogram',
                    'heeft icoon',
                    'heeft je toegevoegd',
                    'heeft je verwijderd',
                    'is lid geworden',
                    'heeft de uitnodigingslink',
                    'beheerders',
                    'admin',
                ];
                if (groupEventTokens.some((token) => normalizedText.includes(token))) {
                    return 'group_event';
                }

                const callEventTokens = [
                    'missed voice call',
                    'missed video call',
                    'voice call',
                    'video call',
                    'spraakoproep',
                    'videogesprek',
                    'gemiste oproep',
                ];
                if (callEventTokens.some((token) => normalizedText.includes(token))) {
                    return 'call_event';
                }

                if (normalizedText.includes('disappearing messages') || normalizedText.includes('verdwijnende berichten')) {
                    return 'privacy_event';
                }

                if (messageType === 'system') {
                    return 'system';
                }

                return null;
            };

            const detectAttachmentKind = (filename) => {
                const name = String(filename || '');

                if (/\.(jpe?g|png|gif|webp|bmp|heic|heif|avif)$/i.test(name)) {
                    return 'image';
                }

                if (/\.(mp4|mov|m4v|3gp|webm)$/i.test(name) || name.toUpperCase().includes('-VIDEO-')) {
                    return 'video';
                }

                if (/\.(opus|ogg|oga|mp3|m4a|aac|wav|webm)$/i.test(name) || name.toUpperCase().includes('-AUDIO-')) {
                    return 'audio';
                }

                return 'file';
            };

            const formatSeconds = (value) => {
                if (!Number.isFinite(value) || value < 0) {
                    return '0:00';
                }
                const minutes = Math.floor(value / 60);
                const seconds = Math.floor(value % 60);
                return `${minutes}:${String(seconds).padStart(2, '0')}`;
            };

            const normalizeZipPath = (value) => {
                const raw = String(value || '').replace(/\\/g, '/').trim();
                const parts = [];
                raw.split('/').forEach((part) => {
                    if (part === '' || part === '.') {
                        return;
                    }
                    if (part === '..') {
                        parts.pop();
                        return;
                    }
                    parts.push(part);
                });
                return parts.join('/');
            };

            const basenameFromPath = (path) => {
                const clean = normalizeZipPath(path);
                if (!clean.includes('/')) {
                    return clean;
                }
                return clean.split('/').pop() || '';
            };

            const folderDisplayNameFromPath = (chatFolderPath, zipFileName) => {
                let folderName = '';
                const normalizedFolder = normalizeZipPath(chatFolderPath);
                if (normalizedFolder !== '') {
                    folderName = normalizedFolder.split('/').pop() || '';
                }

                if (folderName === '') {
                    folderName = String(zipFileName || '').replace(/\.zip$/i, '').trim();
                }

                if (folderName === '') {
                    return 'Contact';
                }

                const directMatch = folderName.match(/^WhatsApp Chat\s*-\s*(.+)$/iu);
                if (directMatch) {
                    return directMatch[1].trim() || 'Contact';
                }

                const withMatch = folderName.match(/^WhatsApp Chat(?: with)?\s+(.+)$/iu);
                if (withMatch) {
                    return withMatch[1].trim() || 'Contact';
                }

                return folderName;
            };

            const collectSenders = (messages) => {
                const senders = [];
                messages.forEach((message) => {
                    if (
                        message
                        && message.type === 'message'
                        && typeof message.sender === 'string'
                        && message.sender.trim() !== ''
                        && !senders.includes(message.sender)
                    ) {
                        senders.push(message.sender);
                    }
                });
                return senders;
            };

            const deriveParticipants = (messages, folderTitle) => {
                const senders = collectSenders(messages);
                const contactComparable = normalizeForCompare(folderTitle);
                let contactName = folderTitle || 'Contact';

                for (const sender of senders) {
                    if (normalizeForCompare(sender) === contactComparable) {
                        contactName = sender;
                        break;
                    }
                }

                let ownerName = 'Ik';
                const ownerAliases = new Set(['you', 'ik', 'me', 'jij']);
                for (const sender of senders) {
                    if (ownerAliases.has(normalizeForCompare(sender))) {
                        ownerName = sender;
                        break;
                    }
                }

                if (ownerName === 'Ik') {
                    for (const sender of senders) {
                        if (!samePerson(sender, contactName)) {
                            ownerName = sender;
                            break;
                        }
                    }
                }

                const hasGroupSystemEvents = messages.some((message) => {
                    if (!message || message.type !== 'system') {
                        return false;
                    }
                    return detectWhatsAppNoticeType(message.sender, message.text, 'system') === 'group_event';
                });
                const isGroupChat = senders.length > 2 || hasGroupSystemEvents;

                return {
                    contactName,
                    ownerName,
                    isGroupChat,
                    senders,
                };
            };

            const revokeObjectUrls = (urls) => {
                urls.forEach((url) => {
                    URL.revokeObjectURL(url);
                });
            };

            const findAttachmentEntry = (attachmentName, chatFolderPath, byPathLower, byBaseLower) => {
                const cleanName = normalizeZipPath(attachmentName);
                if (cleanName === '') {
                    return null;
                }

                const normalizedFolder = normalizeZipPath(chatFolderPath);
                const candidates = [];

                if (normalizedFolder !== '') {
                    candidates.push(normalizeZipPath(`${normalizedFolder}/${cleanName}`));
                }
                candidates.push(cleanName);
                candidates.push(basenameFromPath(cleanName));

                for (const candidate of candidates) {
                    const found = byPathLower.get(candidate.toLowerCase());
                    if (found) {
                        return found;
                    }
                }

                const base = basenameFromPath(cleanName).toLowerCase();
                const byBaseCandidates = byBaseLower.get(base);
                if (!Array.isArray(byBaseCandidates) || byBaseCandidates.length === 0) {
                    return null;
                }

                if (normalizedFolder !== '') {
                    const prefix = `${normalizedFolder.toLowerCase()}/`;
                    const preferred = byBaseCandidates.find((item) => item.path.toLowerCase().startsWith(prefix));
                    if (preferred) {
                        return preferred;
                    }
                }

                return byBaseCandidates[0];
            };

            const parseZipExport = async (zipFile) => {
                if (typeof window.JSZip === 'undefined') {
                    throw new Error('ZIP support could not be loaded in the browser.');
                }

                const zip = await window.JSZip.loadAsync(zipFile);
                const allFiles = [];
                const byPathLower = new Map();
                const byBaseLower = new Map();

                zip.forEach((relativePath, entry) => {
                    if (entry.dir) {
                        return;
                    }

                    const path = normalizeZipPath(relativePath);
                    const indexed = { path, entry };
                    allFiles.push(indexed);
                    byPathLower.set(path.toLowerCase(), indexed);

                    const base = basenameFromPath(path).toLowerCase();
                    if (!byBaseLower.has(base)) {
                        byBaseLower.set(base, []);
                    }
                    byBaseLower.get(base).push(indexed);
                });

                const chatCandidates = allFiles
                    .filter((item) => basenameFromPath(item.path).toLowerCase() === '_chat.txt')
                    .sort((a, b) => a.path.localeCompare(b.path, 'nl'));

                if (chatCandidates.length === 0) {
                    throw new Error('No _chat.txt found in this ZIP export.');
                }

                const chatItem = chatCandidates[0];
                const chatText = await chatItem.entry.async('string');
                const messages = parseChatMessagesFromText(chatText);
                if (messages.length === 0) {
                    throw new Error('Chat file found, but no messages could be read.');
                }

                const chatFolderPath = chatItem.path.includes('/')
                    ? chatItem.path.slice(0, chatItem.path.lastIndexOf('/'))
                    : '';
                const folderTitle = folderDisplayNameFromPath(chatFolderPath, zipFile.name);
                const participants = deriveParticipants(messages, folderTitle);

                const objectUrls = new Set();
                const urlCache = new Map();

                const blobUrlForEntry = async (indexedEntry, preferredType) => {
                    const key = indexedEntry.path;
                    if (urlCache.has(key)) {
                        return urlCache.get(key);
                    }

                    const bytes = await indexedEntry.entry.async('uint8array');
                    const type = preferredType || mimeTypeForFilename(indexedEntry.path);
                    const blob = type ? new Blob([bytes], { type }) : new Blob([bytes]);
                    const url = URL.createObjectURL(blob);
                    objectUrls.add(url);
                    urlCache.set(key, url);
                    return url;
                };

                try {
                    const resolvedMessages = [];
                    for (const message of messages) {
                        let resolvedAttachment = null;
                        if (typeof message.attachment === 'string' && message.attachment.trim() !== '') {
                            const attachmentEntry = findAttachmentEntry(
                                message.attachment,
                                chatFolderPath,
                                byPathLower,
                                byBaseLower
                            );

                            if (attachmentEntry) {
                                const kind = detectAttachmentKind(message.attachment);
                                const url = await blobUrlForEntry(attachmentEntry, mimeTypeForFilename(message.attachment));
                                resolvedAttachment = {
                                    name: message.attachment,
                                    url,
                                    kind,
                                    is_image: kind === 'image',
                                    is_video: kind === 'video',
                                    is_audio: kind === 'audio',
                                };
                            }
                        }

                        resolvedMessages.push({
                            ...message,
                            resolvedAttachment,
                        });
                    }

                    return {
                        headerTitle: folderTitle,
                        contactName: participants.contactName,
                        ownerName: participants.ownerName,
                        isGroupChat: participants.isGroupChat,
                        senders: participants.senders,
                        profilePhotoUrl: staticProfilePhotoUrl,
                        myProfilePhotoUrl: staticMyProfilePhotoUrl,
                        messages: resolvedMessages,
                        objectUrls,
                    };
                } catch (error) {
                    revokeObjectUrls(objectUrls);
                    throw error;
                }
            };

            const applyTransform = () => {
                if (currentMediaKind !== 'image') {
                    return;
                }
                viewerImage.style.transform = `translate(${panX}px, ${panY}px) scale(${scale})`;
            };

            const clampScale = (value) => Math.min(MAX_ZOOM, Math.max(MIN_ZOOM, value));

            const setZoom = (nextScale) => {
                if (currentMediaKind !== 'image') {
                    return;
                }

                scale = clampScale(nextScale);
                if (scale === 1) {
                    panX = 0;
                    panY = 0;
                }
                applyTransform();
            };

            const resetImageState = () => {
                scale = 1;
                panX = 0;
                panY = 0;
                applyTransform();
            };

            const setMediaGallery = (items) => {
                mediaGallery = Array.isArray(items) ? items : [];
                galleryThumbButtons = [];
                viewerStrip.innerHTML = '';
                if (currentMediaIndex >= mediaGallery.length) {
                    closeViewer();
                }
            };

            const navigateGalleryBy = (offset) => {
                const nextIndex = currentMediaIndex + offset;
                if (nextIndex < 0 || nextIndex >= mediaGallery.length) {
                    return false;
                }
                showGalleryItem(nextIndex);
                return true;
            };

            const isImageViewerOpen = () =>
                viewer.getAttribute('aria-hidden') === 'false' && currentMediaKind === 'image';

            const updateVideoDurationLabel = (video) => {
                const durationNode = video.parentElement?.querySelector('[data-video-duration]');
                if (!durationNode) {
                    return;
                }
                durationNode.textContent = formatSeconds(video.duration);
            };

            const primeVideoPreview = (video) => {
                if (video.dataset.previewBound === '1') {
                    updateVideoDurationLabel(video);
                    return;
                }

                video.dataset.previewBound = '1';
                video.muted = true;

                const seekToPreviewFrame = () => {
                    updateVideoDurationLabel(video);

                    if (video.dataset.previewReady === '1' || !Number.isFinite(video.duration) || video.duration <= 0) {
                        return;
                    }

                    const targetTime = Math.min(0.1, Math.max(video.duration * 0.02, 0.01));
                    const handleSeeked = () => {
                        video.pause();
                        video.dataset.previewReady = '1';
                        video.removeEventListener('seeked', handleSeeked);
                    };

                    video.addEventListener('seeked', handleSeeked);

                    try {
                        video.currentTime = targetTime;
                    } catch (error) {
                        video.dataset.previewReady = '1';
                        video.removeEventListener('seeked', handleSeeked);
                    }
                };

                if (video.readyState >= 1) {
                    seekToPreviewFrame();
                }

                video.addEventListener('loadedmetadata', seekToPreviewFrame, { once: true });
                video.addEventListener('durationchange', () => updateVideoDurationLabel(video));
            };

            const updateViewerMode = (kind) => {
                currentMediaKind = kind;
                viewerStage.classList.toggle('is-image', kind === 'image');
                viewerStage.classList.toggle('is-video', kind === 'video');
            };

            const scrollActiveThumbIntoView = () => {
                const activeThumb = galleryThumbButtons[currentMediaIndex];
                if (!activeThumb) {
                    return;
                }

                const stripRect = viewerStrip.getBoundingClientRect();
                const thumbRect = activeThumb.getBoundingClientRect();
                const stripCenter = stripRect.left + (stripRect.width / 2);
                const thumbCenter = thumbRect.left + (thumbRect.width / 2);
                const targetLeft = viewerStrip.scrollLeft + (thumbCenter - stripCenter);

                viewerStrip.scrollTo({
                    left: targetLeft,
                    behavior: 'smooth',
                });
            };

            const showGalleryItem = (index) => {
                const item = mediaGallery[index];
                if (!item) {
                    return;
                }

                currentMediaIndex = index;
                galleryThumbButtons.forEach((button, buttonIndex) => {
                    button.classList.toggle('is-active', buttonIndex === index);
                });

                if (item.kind === 'video') {
                    updateViewerMode('video');
                    viewerImage.hidden = true;
                    viewerImage.removeAttribute('src');
                    viewerVideo.hidden = false;
                    viewerVideo.classList.add('is-visible');
                    viewerVideoPlay.classList.add('is-visible');
                    viewerVideo.pause();

                    if (viewerVideo.getAttribute('src') !== item.url) {
                        viewerVideo.src = item.url;
                        viewerVideo.load();
                    }

                    const showPreviewFrame = () => {
                        if (!Number.isFinite(viewerVideo.duration) || viewerVideo.duration <= 0) {
                            return;
                        }

                        const targetTime = Math.min(0.1, Math.max(viewerVideo.duration * 0.02, 0.01));
                        try {
                            viewerVideo.currentTime = targetTime;
                        } catch (error) {
                            // Ignore seek failures from unsupported codecs.
                        }
                    };

                    if (viewerVideo.readyState >= 1) {
                        showPreviewFrame();
                    } else {
                        viewerVideo.addEventListener('loadedmetadata', showPreviewFrame, { once: true });
                    }
                } else {
                    updateViewerMode('image');
                    viewerVideo.pause();
                    viewerVideo.removeAttribute('src');
                    viewerVideo.load();
                    viewerVideo.hidden = true;
                    viewerVideo.classList.remove('is-visible');
                    viewerVideoPlay.classList.remove('is-visible');

                    viewerImage.hidden = false;
                    viewerImage.src = item.url;
                    viewerImage.alt = item.name || 'Vergrote afbeelding';
                    resetImageState();
                }

                scrollActiveThumbIntoView();
            };

            const renderViewerStrip = () => {
                viewerStrip.innerHTML = '';

                mediaGallery.forEach((item, index) => {
                    const thumbButton = document.createElement('button');
                    thumbButton.type = 'button';
                    thumbButton.className = 'viewer-thumb';
                    thumbButton.dataset.galleryIndex = String(index);
                    thumbButton.setAttribute('aria-label', item.kind === 'video' ? 'Open video' : 'Open image');

                    if (item.kind === 'video') {
                        const thumbVideo = document.createElement('video');
                        thumbVideo.className = 'video-preview-media';
                        thumbVideo.muted = true;
                        thumbVideo.playsInline = true;
                        thumbVideo.disablePictureInPicture = true;
                        thumbVideo.disableRemotePlayback = true;
                        thumbVideo.preload = 'auto';
                        thumbVideo.setAttribute('data-preview-video', '');

                        const thumbSource = document.createElement('source');
                        thumbSource.src = item.url;
                        thumbVideo.appendChild(thumbSource);
                        thumbButton.appendChild(thumbVideo);

                        const playBadge = document.createElement('span');
                        playBadge.className = 'video-play-badge';
                        playBadge.setAttribute('aria-hidden', 'true');
                        thumbButton.appendChild(playBadge);

                        const duration = document.createElement('span');
                        duration.className = 'video-duration';
                        duration.setAttribute('data-video-duration', '');
                        duration.textContent = '0:00';
                        thumbButton.appendChild(duration);

                        primeVideoPreview(thumbVideo);
                    } else {
                        const image = document.createElement('img');
                        image.src = item.url;
                        image.alt = item.name || '';
                        image.loading = 'lazy';
                        thumbButton.appendChild(image);
                    }

                    thumbButton.addEventListener('click', () => showGalleryItem(index));
                    viewerStrip.appendChild(thumbButton);
                });

                galleryThumbButtons = Array.from(viewerStrip.querySelectorAll('.viewer-thumb'));
            };

            const openViewer = (index) => {
                if (!Number.isInteger(index) || !mediaGallery[index]) {
                    return;
                }

                if (galleryThumbButtons.length === 0) {
                    renderViewerStrip();
                }

                resetImageState();
                viewer.setAttribute('aria-hidden', 'false');
                document.body.style.overflow = 'hidden';
                showGalleryItem(index);
            };

            function closeViewer() {
                viewer.setAttribute('aria-hidden', 'true');
                viewerImage.hidden = true;
                viewerImage.removeAttribute('src');
                viewerVideo.pause();
                viewerVideo.removeAttribute('src');
                viewerVideo.load();
                viewerVideo.hidden = true;
                viewerVideo.classList.remove('is-visible');
                viewerVideoPlay.classList.remove('is-visible');
                document.body.style.overflow = '';
                currentMediaIndex = -1;
                currentMediaKind = null;
                galleryThumbButtons.forEach((button) => button.classList.remove('is-active'));
            }

            const buildAudioWaveform = (card) => {
                const waveform = card.querySelector('[data-audio-waveform]');
                const audio = card.querySelector('[data-audio]');
                if (!waveform || !audio || waveform.dataset.built === '1') {
                    return;
                }

                const source = audio.querySelector('source');
                const sourceKey = source ? (source.getAttribute('src') || '') : '';
                let seed = 0;

                for (let i = 0; i < sourceKey.length; i += 1) {
                    seed = ((seed * 31) + sourceKey.charCodeAt(i)) >>> 0;
                }
                if (seed === 0) {
                    seed = 1337;
                }

                const barsCount = 46;
                waveform.style.setProperty('--bars', String(barsCount));

                for (let i = 0; i < barsCount; i += 1) {
                    seed = (1664525 * seed + 1013904223) >>> 0;
                    const noise = seed / 4294967295;
                    const t = i / (barsCount - 1);
                    const centerBias = 1 - Math.pow(Math.abs((t * 2) - 1), 1.35);
                    const dynamic = (0.32 + (0.68 * noise)) * (0.45 + (0.55 * centerBias));
                    const height = Math.round(6 + dynamic * 29);

                    const bar = document.createElement('span');
                    bar.className = 'audio-bar';
                    bar.style.setProperty('--h', String(Math.min(33, Math.max(6, height))));
                    waveform.appendChild(bar);
                }

                waveform.dataset.built = '1';
            };

            const syncAudioCard = (card) => {
                const audio = card.querySelector('[data-audio]');
                const range = card.querySelector('[data-audio-range]');
                const duration = card.querySelector('[data-audio-duration]');
                const track = card.querySelector('[data-audio-track]');
                const waveform = card.querySelector('[data-audio-waveform]');

                if (!audio || !range || !duration || !track || !waveform) {
                    return;
                }

                const progress = audio.duration ? (audio.currentTime / audio.duration) * 100 : 0;
                const safeProgress = Number.isFinite(progress) ? progress : 0;
                range.value = String(safeProgress);
                track.style.setProperty('--progress', `${safeProgress}%`);
                if (audio.duration && Number.isFinite(audio.duration)) {
                    duration.textContent = `${formatSeconds(audio.currentTime)} / ${formatSeconds(audio.duration)}`;
                } else {
                    duration.textContent = '0:00';
                }

                if (waveform.dataset.built !== '1') {
                    return;
                }

                const bars = waveform.querySelectorAll('.audio-bar');
                const playedBars = Math.max(0, Math.min(
                    bars.length,
                    Math.round((safeProgress / 100) * bars.length)
                ));
                bars.forEach((bar, index) => {
                    bar.classList.toggle('is-played', index < playedBars);
                });
            };

            const ensureAudioCardPrepared = (card) => {
                if (card.dataset.audioPrepared === '1') {
                    return;
                }
                buildAudioWaveform(card);
                card.dataset.audioPrepared = '1';
                syncAudioCard(card);
            };

            const stopAudioAnimation = (audio) => {
                const rafId = audioAnimationFrames.get(audio);
                if (rafId) {
                    cancelAnimationFrame(rafId);
                    audioAnimationFrames.delete(audio);
                }
            };

            const startAudioAnimation = (card, audio) => {
                stopAudioAnimation(audio);
                const tick = () => {
                    if (audio.paused || audio.ended) {
                        stopAudioAnimation(audio);
                        return;
                    }
                    syncAudioCard(card);
                    audioAnimationFrames.set(audio, requestAnimationFrame(tick));
                };
                audioAnimationFrames.set(audio, requestAnimationFrame(tick));
            };

            const pauseAudioCard = (card, reset = false) => {
                const audio = card.querySelector('[data-audio]');
                if (!audio) {
                    return;
                }

                audio.pause();
                stopAudioAnimation(audio);
                if (reset) {
                    audio.currentTime = 0;
                }

                card.classList.remove('is-playing');
                syncAudioCard(card);

                if (activeAudio === audio) {
                    activeAudio = null;
                }
            };

            const bindAudioCard = (card) => {
                if (card.dataset.bound === '1') {
                    return;
                }
                card.dataset.bound = '1';

                const audio = card.querySelector('[data-audio]');
                const toggle = card.querySelector('[data-audio-toggle]');
                const range = card.querySelector('[data-audio-range]');
                const track = card.querySelector('[data-audio-track]');
                const thumb = card.querySelector('[data-audio-thumb]');

                if (!audio || !toggle || !range || !track || !thumb) {
                    return;
                }

                let scrubPointerId = null;
                let resumeAfterScrub = false;

                const scrubToClientX = (clientX) => {
                    if (!audio.duration || !Number.isFinite(audio.duration)) {
                        return;
                    }

                    const rect = track.getBoundingClientRect();
                    if (rect.width <= 0) {
                        return;
                    }

                    const ratio = Math.max(0, Math.min(1, (clientX - rect.left) / rect.width));
                    audio.currentTime = ratio * audio.duration;
                    syncAudioCard(card);
                };

                const finishScrub = async (event) => {
                    if (scrubPointerId === null || event.pointerId !== scrubPointerId) {
                        return;
                    }
                    scrubPointerId = null;
                    thumb.classList.remove('is-dragging');
                    card.classList.remove('is-scrubbing');
                    if (thumb.releasePointerCapture && thumb.hasPointerCapture(event.pointerId)) {
                        thumb.releasePointerCapture(event.pointerId);
                    }

                    if (resumeAfterScrub) {
                        resumeAfterScrub = false;
                        try {
                            await audio.play();
                        } catch (error) {
                            // Ignore play rejection if autoplay policy blocks it.
                        }
                    }
                };

                toggle.addEventListener('click', async () => {
                    ensureAudioCardPrepared(card);
                    if (!audio.paused) {
                        pauseAudioCard(card);
                        return;
                    }

                    document.querySelectorAll('[data-audio-card].is-playing').forEach((otherCard) => {
                        if (otherCard !== card) {
                            pauseAudioCard(otherCard, false);
                        }
                    });

                    try {
                        if (audio.readyState === 0) {
                            audio.load();
                        }
                        await audio.play();
                        activeAudio = audio;
                        card.classList.add('is-playing');
                        syncAudioCard(card);
                    } catch (error) {
                        card.classList.remove('is-playing');
                    }
                });

                range.addEventListener('input', () => {
                    if (!audio.duration) {
                        return;
                    }
                    audio.currentTime = (Number(range.value) / 100) * audio.duration;
                    syncAudioCard(card);
                });

                track.addEventListener('pointerdown', (event) => {
                    if (event.target === thumb) {
                        return;
                    }
                    ensureAudioCardPrepared(card);
                    if (audio.readyState === 0) {
                        audio.load();
                    }
                    scrubToClientX(event.clientX);
                });

                thumb.addEventListener('pointerdown', (event) => {
                    ensureAudioCardPrepared(card);
                    if (audio.readyState === 0) {
                        audio.load();
                    }
                    if (!audio.duration || !Number.isFinite(audio.duration)) {
                        return;
                    }

                    scrubPointerId = event.pointerId;
                    resumeAfterScrub = !audio.paused;
                    if (resumeAfterScrub) {
                        audio.pause();
                    }

                    card.classList.add('is-scrubbing');
                    thumb.classList.add('is-dragging');
                    if (thumb.setPointerCapture) {
                        thumb.setPointerCapture(event.pointerId);
                    }
                    scrubToClientX(event.clientX);
                    event.preventDefault();
                });

                thumb.addEventListener('pointermove', (event) => {
                    if (scrubPointerId === null || event.pointerId !== scrubPointerId) {
                        return;
                    }
                    scrubToClientX(event.clientX);
                    event.preventDefault();
                });

                thumb.addEventListener('pointerup', finishScrub);
                thumb.addEventListener('pointercancel', finishScrub);

                audio.addEventListener('loadedmetadata', () => {
                    ensureAudioCardPrepared(card);
                    syncAudioCard(card);
                });
                audio.addEventListener('timeupdate', () => syncAudioCard(card));
                audio.addEventListener('play', () => {
                    card.classList.add('is-playing');
                    activeAudio = audio;
                    ensureAudioCardPrepared(card);
                    startAudioAnimation(card, audio);
                    syncAudioCard(card);
                });
                audio.addEventListener('pause', () => {
                    stopAudioAnimation(audio);
                    card.classList.remove('is-playing');
                    syncAudioCard(card);
                    if (activeAudio === audio) {
                        activeAudio = null;
                    }
                });
                audio.addEventListener('ended', () => pauseAudioCard(card, true));
            };

            const initializeInteractiveElements = (root = document) => {
                if (!audioCardObserver && 'IntersectionObserver' in window) {
                    audioCardObserver = new IntersectionObserver((entries) => {
                        entries.forEach((entry) => {
                            if (!entry.isIntersecting) {
                                return;
                            }
                            ensureAudioCardPrepared(entry.target);
                            audioCardObserver.unobserve(entry.target);
                        });
                    }, {
                        root: null,
                        rootMargin: '220px 0px',
                        threshold: 0.01,
                    });
                }

                root.querySelectorAll('[data-audio-card]').forEach((card) => {
                    bindAudioCard(card);
                    if (audioCardObserver) {
                        audioCardObserver.observe(card);
                    } else {
                        ensureAudioCardPrepared(card);
                    }
                });
                root.querySelectorAll('[data-preview-video]').forEach((video) => {
                    primeVideoPreview(video);
                });
            };

            const createStatusChecksNode = () => {
                const checks = document.createElement('span');
                checks.className = 'status-checks';
                checks.setAttribute('aria-hidden', 'true');
                checks.innerHTML = iconCheckSvg;
                return checks;
            };

            const createBubbleTextNode = (text, rowClass, timeLabel, inlineMeta) => {
                if (!inlineMeta) {
                    const textNode = document.createElement('div');
                    textNode.className = 'bubble-text';
                    textNode.innerHTML = renderMessageText(text);
                    return textNode;
                }

                const inlineNode = document.createElement('div');
                inlineNode.className = 'bubble-text bubble-text-inline';

                const main = document.createElement('span');
                main.className = 'bubble-inline-main';
                main.innerHTML = renderMessageText(text);
                inlineNode.appendChild(main);

                const metaInline = document.createElement('span');
                metaInline.className = 'bubble-meta-inline';

                const time = document.createElement('time');
                time.textContent = timeLabel;
                metaInline.appendChild(time);

                if (rowClass === 'outgoing') {
                    metaInline.appendChild(createStatusChecksNode());
                }

                inlineNode.appendChild(metaInline);
                return inlineNode;
            };

            const createGroupSenderMetaNode = (senderName) => {
                const meta = document.createElement('div');
                meta.className = 'group-sender-meta';
                applySenderPalette(meta, senderName);

                const avatar = document.createElement('span');
                avatar.className = 'group-sender-avatar';
                avatar.setAttribute('aria-hidden', 'true');
                avatar.textContent = firstInitial(senderName);
                meta.appendChild(avatar);

                const name = document.createElement('span');
                name.className = 'group-sender-name';
                name.textContent = senderName;
                meta.appendChild(name);

                return meta;
            };

            const createAttachmentNode = (attachment, rowClass, timeLabel, galleryIndex, context, messageSender = '') => {
                const wrapper = document.createElement('div');
                wrapper.className = 'attachment';

                if (attachment.is_image) {
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'image-button';
                    button.dataset.galleryIndex = String(galleryIndex);
                    button.setAttribute('aria-label', 'Open image');

                    const image = document.createElement('img');
                    image.src = attachment.url;
                    image.alt = attachment.name;
                    image.loading = 'lazy';
                    button.appendChild(image);
                    wrapper.appendChild(button);
                    return wrapper;
                }

                if (attachment.is_video) {
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'video-preview-button';
                    button.dataset.galleryIndex = String(galleryIndex);
                    button.setAttribute('aria-label', 'Open video');

                    const video = document.createElement('video');
                    video.className = 'video-preview-media';
                    video.muted = true;
                    video.playsInline = true;
                    video.disablePictureInPicture = true;
                    video.disableRemotePlayback = true;
                    video.preload = 'auto';
                    video.setAttribute('data-preview-video', '');
                    const source = document.createElement('source');
                    source.src = attachment.url;
                    const mime = mimeTypeForFilename(attachment.name);
                    if (mime) {
                        source.type = mime;
                    }
                    video.appendChild(source);
                    button.appendChild(video);

                    const playBadge = document.createElement('span');
                    playBadge.className = 'video-play-badge';
                    playBadge.setAttribute('aria-hidden', 'true');
                    button.appendChild(playBadge);

                    const meta = document.createElement('span');
                    meta.className = 'video-preview-meta';
                    meta.setAttribute('aria-hidden', 'true');

                    const left = document.createElement('span');
                    left.className = 'video-preview-left';
                    const icon = document.createElement('span');
                    icon.className = 'video-preview-icon';
                    icon.innerHTML = iconVideoSvg;
                    left.appendChild(icon);
                    const duration = document.createElement('span');
                    duration.className = 'video-preview-duration';
                    duration.setAttribute('data-video-duration', '');
                    duration.textContent = '0:00';
                    left.appendChild(duration);

                    const right = document.createElement('span');
                    right.className = 'video-preview-right';
                    const time = document.createElement('span');
                    time.className = 'video-preview-time';
                    time.textContent = timeLabel;
                    right.appendChild(time);
                    if (rowClass === 'outgoing') {
                        const status = document.createElement('span');
                        status.className = 'video-preview-status';
                        status.innerHTML = iconCheckSvg;
                        right.appendChild(status);
                    }

                    meta.appendChild(left);
                    meta.appendChild(right);
                    button.appendChild(meta);

                    wrapper.appendChild(button);
                    return wrapper;
                }

                if (attachment.is_audio) {
                    const isOutgoingAudio = rowClass === 'outgoing';
                    const audioCard = document.createElement('div');
                    audioCard.className = isOutgoingAudio ? 'audio-card outgoing-audio' : 'audio-card incoming-audio';
                    audioCard.setAttribute('data-audio-card', '');
                    if (!isOutgoingAudio && context.isGroupChat && String(messageSender).trim() !== '') {
                        audioCard.setAttribute('data-audio-sender', String(messageSender).trim());
                    }

                    const avatar = document.createElement('div');
                    avatar.className = 'audio-avatar';
                    let avatarUrl = isOutgoingAudio ? context.myProfilePhotoUrl : context.profilePhotoUrl;
                    let avatarName = isOutgoingAudio ? context.ownerName : context.contactName;
                    const senderName = String(messageSender || '').trim();
                    const useGroupFallback = !isOutgoingAudio && context.isGroupChat && senderName !== '';
                    if (useGroupFallback) {
                        avatarUrl = null;
                        avatarName = senderName;
                        applySenderPalette(avatar, senderName);
                    }
                    const avatarInitial = firstInitial(avatarName);

                    if (avatarUrl) {
                        const avatarImage = document.createElement('img');
                        avatarImage.className = 'audio-avatar-image';
                        avatarImage.src = avatarUrl;
                        avatarImage.alt = '';
                        avatar.appendChild(avatarImage);
                    } else {
                        const avatarFallback = document.createElement('span');
                        avatarFallback.className = 'audio-avatar-fallback';
                        avatarFallback.textContent = avatarInitial;
                        avatar.appendChild(avatarFallback);
                    }

                    const badge = document.createElement('span');
                    badge.className = 'audio-avatar-badge';
                    badge.innerHTML = iconMicFilledSvg;
                    avatar.appendChild(badge);
                    audioCard.appendChild(avatar);

                    const toggle = document.createElement('button');
                    toggle.type = 'button';
                    toggle.className = 'audio-toggle';
                    toggle.setAttribute('data-audio-toggle', '');
                    toggle.setAttribute('aria-label', 'Play voice message');
                    const toggleIcon = document.createElement('span');
                    toggleIcon.className = 'audio-toggle-icon';
                    toggleIcon.setAttribute('aria-hidden', 'true');
                    toggle.appendChild(toggleIcon);
                    audioCard.appendChild(toggle);

                    const track = document.createElement('div');
                    track.className = 'audio-track';
                    track.setAttribute('data-audio-track', '');

                    const waveform = document.createElement('div');
                    waveform.className = 'audio-waveform';
                    waveform.setAttribute('data-audio-waveform', '');
                    waveform.setAttribute('aria-hidden', 'true');
                    track.appendChild(waveform);

                    const thumb = document.createElement('div');
                    thumb.className = 'audio-thumb';
                    thumb.setAttribute('data-audio-thumb', '');
                    thumb.setAttribute('aria-hidden', 'true');
                    track.appendChild(thumb);

                    const range = document.createElement('input');
                    range.className = 'audio-range';
                    range.type = 'range';
                    range.min = '0';
                    range.max = '100';
                    range.value = '0';
                    range.step = '0.1';
                    range.setAttribute('data-audio-range', '');
                    range.setAttribute('aria-label', 'Scrub voice message');
                    track.appendChild(range);

                    const duration = document.createElement('div');
                    duration.className = 'audio-duration';
                    duration.setAttribute('data-audio-duration', '');
                    duration.textContent = '0:00';
                    track.appendChild(duration);
                    audioCard.appendChild(track);

                    const audio = document.createElement('audio');
                    audio.className = 'audio-element';
                    audio.setAttribute('data-audio', '');
                    audio.preload = 'none';
                    const source = document.createElement('source');
                    source.src = attachment.url;
                    const mime = mimeTypeForFilename(attachment.name);
                    if (mime) {
                        source.type = mime;
                    }
                    audio.appendChild(source);
                    audioCard.appendChild(audio);

                    wrapper.appendChild(audioCard);
                    return wrapper;
                }

                const fileLink = document.createElement('a');
                fileLink.className = 'file-link';
                fileLink.href = attachment.url;
                fileLink.target = '_blank';
                fileLink.rel = 'noopener noreferrer';
                fileLink.textContent = `Download: ${attachment.name}`;
                wrapper.appendChild(fileLink);
                return wrapper;
            };

            const renderThreadMessages = (messages, context) => {
                if (!thread) {
                    return;
                }

                if (activeAudio) {
                    activeAudio.pause();
                    activeAudio = null;
                }

                closeViewer();
                thread.innerHTML = '';

                if (!Array.isArray(messages) || messages.length === 0) {
                    const empty = document.createElement('div');
                    empty.className = 'empty-state';
                    empty.textContent = 'Chat file found, but no messages could be read.';
                    thread.appendChild(empty);
                    setMediaGallery([]);
                    initializeTimelineControls();
                    reapplySearchAfterRender();
                    restorePageScrollPosition();
                    return;
                }

                const messageGalleryIndex = new Map();
                const galleryItems = [];

                messages.forEach((message, index) => {
                    const attachment = message.resolvedAttachment || null;
                    if (attachment && (attachment.is_image || attachment.is_video)) {
                        const galleryIndex = galleryItems.length;
                        messageGalleryIndex.set(index, galleryIndex);
                        galleryItems.push({
                            kind: attachment.kind,
                            url: attachment.url,
                            name: attachment.name,
                            time: formatTimeLabel(message.datetime, message.time_raw),
                            date: formatDateChip(message.datetime, message.date_raw),
                        });
                    }
                });

                let lastDateKey = null;

                messages.forEach((message, index) => {
                    const dateKey = dateKeyForMessage(message.datetime, message.date_raw);
                    if (dateKey !== lastDateKey) {
                        lastDateKey = dateKey;
                        const dateChip = document.createElement('div');
                        dateChip.className = 'date-chip';
                        dateChip.textContent = formatDateChip(message.datetime, message.date_raw);
                        thread.appendChild(dateChip);
                    }

                    let rowClass = 'system';
                    if (message.type === 'message') {
                        rowClass = samePerson(message.sender || '', context.ownerName) ? 'outgoing' : 'incoming';
                    }

                    const timeLabel = formatTimeLabel(message.datetime, message.time_raw);
                    const attachment = message.resolvedAttachment || null;
                    const hasVideoAttachment = !!(attachment && attachment.is_video);
                    const messageText = String(message.text || '');
                    const renderTextBelowMedia = !!(attachment && (attachment.is_image || attachment.is_video) && messageText !== '');
                    const showInlineMetaWithText = message.type === 'message'
                        && !hasVideoAttachment
                        && messageText !== ''
                        && message.edited !== true
                        && shouldInlineMetaWithText(messageText);
                    const senderName = typeof message.sender === 'string' ? message.sender.trim() : '';
                    const showGroupSenderMeta = !!(context.isGroupChat && rowClass === 'incoming' && message.type === 'message' && senderName !== '');

                    const noticeType = detectWhatsAppNoticeType(message.sender, messageText, message.type);
                    if (noticeType !== null && attachment === null) {
                        if (noticeType === 'group_event') {
                            const noticeRow = document.createElement('article');
                            noticeRow.className = 'msg-row system-event-row';
                            const chip = document.createElement('div');
                            chip.className = 'system-event-chip';
                            chip.innerHTML = renderMessageText(messageText);
                            noticeRow.appendChild(chip);
                            thread.appendChild(noticeRow);
                            return;
                        }

                        const noticeRow = document.createElement('article');
                        noticeRow.className = 'msg-row system-notice';

                        const noticeCard = document.createElement('div');
                        noticeCard.className = `notice-card notice-${noticeType}`;

                        const noticeContent = document.createElement('div');
                        noticeContent.className = 'notice-content';

                        const noticeIcon = document.createElement('span');
                        noticeIcon.className = 'notice-icon';
                        noticeIcon.setAttribute('aria-hidden', 'true');
                        noticeIcon.innerHTML = noticeType === 'encryption' ? iconLockSvg : iconCircleUserRoundSvg;

                        const noticeText = document.createElement('div');
                        noticeText.className = 'notice-text';
                        noticeText.innerHTML = renderMessageText(messageText);

                        noticeContent.appendChild(noticeIcon);
                        noticeContent.appendChild(noticeText);
                        noticeCard.appendChild(noticeContent);
                        noticeRow.appendChild(noticeCard);
                        thread.appendChild(noticeRow);
                        return;
                    }

                    const row = document.createElement('article');
                    row.className = `msg-row ${rowClass}`;

                    const bubbleClasses = ['bubble'];
                    if (attachment) {
                        bubbleClasses.push('has-media', `media-${attachment.kind}`);
                        if (messageText === '') {
                            bubbleClasses.push('media-only');
                        }
                    }

                    const bubble = document.createElement('div');
                    bubble.className = bubbleClasses.join(' ');

                    if (showGroupSenderMeta) {
                        bubble.appendChild(createGroupSenderMetaNode(senderName));
                    }

                    if (messageText !== '' && !renderTextBelowMedia) {
                        bubble.appendChild(createBubbleTextNode(messageText, rowClass, timeLabel, showInlineMetaWithText));
                    }

                    if (attachment) {
                        const galleryIndex = messageGalleryIndex.get(index);
                        bubble.appendChild(createAttachmentNode(attachment, rowClass, timeLabel, galleryIndex, context, senderName));
                    } else if (typeof message.attachment === 'string' && message.attachment.trim() !== '') {
                        const omittedAttachment = document.createElement('div');
                        omittedAttachment.className = 'omitted';
                        omittedAttachment.textContent = `File not found: ${message.attachment}`;
                        bubble.appendChild(omittedAttachment);
                    }

                    if (messageText !== '' && renderTextBelowMedia) {
                        bubble.appendChild(createBubbleTextNode(messageText, rowClass, timeLabel, showInlineMetaWithText));
                    }

                    if (typeof message.media_omitted === 'string' && message.media_omitted.trim() !== '') {
                        const omitted = document.createElement('div');
                        omitted.className = 'omitted';
                        omitted.textContent = `${message.media_omitted.charAt(0).toUpperCase()}${message.media_omitted.slice(1)} not included in export.`;
                        bubble.appendChild(omitted);
                    }

                    if (message.type === 'message' && !hasVideoAttachment && !showInlineMetaWithText) {
                        const meta = document.createElement('div');
                        meta.className = 'bubble-meta';

                        if (message.edited === true) {
                            const edited = document.createElement('span');
                            edited.className = 'edited';
                            edited.textContent = 'edited';
                            meta.appendChild(edited);
                        }

                        const time = document.createElement('time');
                        time.textContent = timeLabel;
                        meta.appendChild(time);

                        if (rowClass === 'outgoing') {
                            meta.appendChild(createStatusChecksNode());
                        }

                        bubble.appendChild(meta);
                    }

                    row.appendChild(bubble);
                    thread.appendChild(row);
                });

                setMediaGallery(galleryItems);
                initializeInteractiveElements(thread);
                initializeTimelineControls();
                reapplySearchAfterRender();
                restorePageScrollPosition();
            };

            const applyChatModel = (model) => {
                currentMessages = Array.isArray(model.messages) ? model.messages : [];
                baseProfilePhotoUrl = model.profilePhotoUrl || null;
                baseMyProfilePhotoUrl = model.myProfilePhotoUrl || null;
                const senderPool = Array.isArray(model.senders) ? model.senders : [];
                const savedMapping = getSavedParticipantMapping(model.headerTitle, senderPool);
                const ownerName = savedMapping ? savedMapping.ownerName : model.ownerName;
                const contactName = savedMapping ? savedMapping.contactName : model.contactName;

                currentContext = {
                    headerTitle: model.headerTitle,
                    ownerName: ownerName,
                    contactName: contactName,
                    isGroupChat: !!model.isGroupChat,
                    senders: senderPool,
                    profilePhotoUrl: customProfilePhotoUrl || baseProfilePhotoUrl,
                    myProfilePhotoUrl: customMyProfilePhotoUrl || baseMyProfilePhotoUrl,
                };

                if (topbarTitle) {
                    topbarTitle.textContent = model.headerTitle;
                }
                document.title = `${model.headerTitle} - WhatsApp Memory`;
                renderTopbarAvatar(currentContext.profilePhotoUrl);
                renderThreadMessages(currentMessages, currentContext);
            };

            const applyProfilePhotoSelection = (type, silent = false) => {
                const isOwner = type === 'owner';
                const input = isOwner ? myPhotoInput : contactPhotoInput;
                if (!input || !input.files || !input.files[0]) {
                    if (!silent) {
                        setUploadStatus('Choose an image first.', true);
                    }
                    return;
                }

                const file = input.files[0];
                if (file.type && !file.type.startsWith('image/')) {
                    if (!silent) {
                        setUploadStatus('Choose an image file (jpg/png).', true);
                    }
                    return;
                }

                const url = URL.createObjectURL(file);
                if (isOwner) {
                    if (customMyObjectUrl) {
                        URL.revokeObjectURL(customMyObjectUrl);
                    }
                    customMyObjectUrl = url;
                    customMyProfilePhotoUrl = url;
                    writeCachedProfilePhoto(PROFILE_CACHE_MY_KEY, file).catch(() => {});
                } else {
                    if (customProfileObjectUrl) {
                        URL.revokeObjectURL(customProfileObjectUrl);
                    }
                    customProfileObjectUrl = url;
                    customProfilePhotoUrl = url;
                    writeCachedProfilePhoto(PROFILE_CACHE_CONTACT_KEY, file).catch(() => {});
                }

                currentContext.profilePhotoUrl = customProfilePhotoUrl || baseProfilePhotoUrl;
                currentContext.myProfilePhotoUrl = customMyProfilePhotoUrl || baseMyProfilePhotoUrl;

                renderTopbarAvatar(currentContext.profilePhotoUrl);
                if (Array.isArray(currentMessages)) {
                    renderThreadMessages(currentMessages, currentContext);
                } else {
                    updateAudioAvatarsInDom();
                }

                const label = isOwner ? 'Your profile photo' : 'Contact profile photo';
                if (!silent) {
                    setUploadStatus(`${label} updated: ${file.name}`, false);
                }
            };

            if (thread) {
                thread.addEventListener('click', (event) => {
                    const trigger = event.target.closest('.image-button[data-gallery-index], .video-preview-button[data-gallery-index]');
                    if (!trigger) {
                        return;
                    }

                    const index = Number(trigger.getAttribute('data-gallery-index'));
                    openViewer(index);
                });
            }

            initializeInteractiveElements(document);
            initializeTimelineControls();
            updateSearchUi();
            loadPendingScrollRestore();
            requestAnimationFrame(() => {
                restorePageScrollPosition();
                queueSyncScrollControls();
            });

            const openSearchPanel = () => {
                if (!searchPanel) {
                    return;
                }
                searchPanel.classList.add('is-open');
                if (toggleSearchBtn) {
                    toggleSearchBtn.setAttribute('aria-expanded', 'true');
                }
                if (searchInput) {
                    searchInput.focus();
                    searchInput.select();
                }
            };

            const closeSearchPanel = () => {
                if (!searchPanel) {
                    return;
                }
                searchPanel.classList.remove('is-open');
                if (toggleSearchBtn) {
                    toggleSearchBtn.setAttribute('aria-expanded', 'false');
                }
                if (searchInput) {
                    searchInput.value = '';
                }
                applySearch('', false);
            };

            if (toggleSearchBtn) {
                toggleSearchBtn.addEventListener('click', () => {
                    if (searchPanel && searchPanel.classList.contains('is-open')) {
                        closeSearchPanel();
                    } else {
                        openSearchPanel();
                    }
                });
            }

            if (searchInput) {
                searchInput.addEventListener('input', () => {
                    applySearch(searchInput.value, false);
                });

                searchInput.addEventListener('keydown', (event) => {
                    if (event.key === 'Enter') {
                        event.preventDefault();
                        navigateSearch(event.shiftKey ? -1 : 1);
                    }
                });
            }

            if (searchPrevBtn) {
                searchPrevBtn.addEventListener('click', () => navigateSearch(-1));
            }

            if (searchNextBtn) {
                searchNextBtn.addEventListener('click', () => navigateSearch(1));
            }

            if (jumpToUploadBtn) {
                jumpToUploadBtn.addEventListener('click', () => {
                    openUploadModal(false);
                });
            }

            if (closeUploadModalBtn) {
                closeUploadModalBtn.addEventListener('click', closeUploadModal);
            }

            if (uploadModal) {
                uploadModal.addEventListener('click', (event) => {
                    if (event.target === uploadModal) {
                        closeUploadModal();
                    }
                });
            }

            if (participantModal) {
                participantModal.addEventListener('click', (event) => {
                    if (event.target === participantModal) {
                        closeParticipantModal();
                    }
                });
            }

            if (participantOwnerSelect && participantContactSelect) {
                participantOwnerSelect.addEventListener('change', () => {
                    if (participantOwnerSelect.value !== participantContactSelect.value) {
                        return;
                    }
                    const senders = uniqueSenderList(currentContext.senders);
                    const fallback = senders.find((name) => name !== participantOwnerSelect.value);
                    if (fallback) {
                        participantContactSelect.value = fallback;
                    }
                });
            }

            if (participantApplyBtn) {
                participantApplyBtn.addEventListener('click', () => {
                    const ownerPicked = participantOwnerSelect ? participantOwnerSelect.value.trim() : '';
                    const contactPicked = participantContactSelect ? participantContactSelect.value.trim() : '';
                    if (ownerPicked === '') {
                        return;
                    }

                    currentContext.ownerName = ownerPicked;
                    currentContext.contactName = contactPicked || ownerPicked;
                    saveParticipantMapping(
                        currentContext.headerTitle,
                        currentContext.senders,
                        currentContext.ownerName,
                        currentContext.contactName
                    );

                    if (Array.isArray(currentMessages)) {
                        renderThreadMessages(currentMessages, currentContext);
                    }
                    closeParticipantModal();
                });
            }

            const openExportHelp = () => {
                if (!exportHelpModal) {
                    return;
                }
                exportHelpModal.setAttribute('aria-hidden', 'false');
            };

            const closeExportHelp = () => {
                if (!exportHelpModal) {
                    return;
                }
                exportHelpModal.setAttribute('aria-hidden', 'true');
            };

            if (openExportHelpBtn) {
                openExportHelpBtn.addEventListener('click', openExportHelp);
            }

            if (closeExportHelpBtn) {
                closeExportHelpBtn.addEventListener('click', closeExportHelp);
            }

            if (exportHelpModal) {
                exportHelpModal.addEventListener('click', (event) => {
                    if (event.target === exportHelpModal) {
                        closeExportHelp();
                    }
                });
            }

            if (scrollBtn) {
                scrollBtn.addEventListener('click', () => {
                    window.scrollTo({
                        top: document.documentElement.scrollHeight,
                        behavior: 'smooth',
                    });
                });
            }

            if (timeline && thumb) {
                const endTimelineDrag = (event) => {
                    if (event && timeline.releasePointerCapture) {
                        try {
                            timeline.releasePointerCapture(event.pointerId);
                        } catch (error) {
                            // Ignore release errors for already released pointers.
                        }
                    }
                    timelineDragging = false;
                    thumb.classList.remove('is-dragging');
                    if (event && Number.isFinite(event.clientY)) {
                        scrollTimelineToRatio(timelineRatioFromPointer(event.clientY), 'smooth', true);
                    } else {
                        queueSyncScrollControls();
                    }
                    hideTimelineDateBubbleSoon();
                };

                timeline.addEventListener('pointerdown', (event) => {
                    if (event.button !== 0 || timeline.classList.contains('is-disabled')) {
                        return;
                    }

                    timelineDragging = true;
                    thumb.classList.add('is-dragging');
                    showTimelineDateBubble();
                    if (timeline.setPointerCapture) {
                        timeline.setPointerCapture(event.pointerId);
                    }
                    scrollTimelineToRatio(timelineRatioFromPointer(event.clientY), 'auto', false);
                    event.preventDefault();
                });

                timeline.addEventListener('pointermove', (event) => {
                    if (!timelineDragging) {
                        return;
                    }
                    showTimelineDateBubble();
                    scrollTimelineToRatio(timelineRatioFromPointer(event.clientY), 'auto', false);
                    event.preventDefault();
                });

                timeline.addEventListener('pointerup', endTimelineDrag);
                timeline.addEventListener('pointercancel', endTimelineDrag);
            }

            window.addEventListener('scroll', () => {
                queueSyncScrollControls();
                savePageScrollPosition();
            }, { passive: true });
            window.addEventListener('resize', () => {
                rebuildTimelineAnchors();
                queueSyncScrollControls();
            });
            window.addEventListener('pagehide', savePageScrollPosition);
            window.addEventListener('beforeunload', savePageScrollPosition);

            closeButton.addEventListener('click', closeViewer);
            viewerVideoPlay.addEventListener('click', async () => {
                try {
                    await viewerVideo.play();
                } catch (error) {
                    // Ignore autoplay-style play rejections.
                }
            });

            viewerVideo.addEventListener('play', () => {
                viewerVideoPlay.classList.remove('is-visible');
            });

            viewerVideo.addEventListener('pause', () => {
                if (viewer.getAttribute('aria-hidden') === 'false') {
                    viewerVideoPlay.classList.add('is-visible');
                }
            });

            viewerVideo.addEventListener('ended', () => {
                viewerVideoPlay.classList.add('is-visible');
            });

            viewer.addEventListener('click', (event) => {
                if (event.target === viewer) {
                    closeViewer();
                }
            });

            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape' && exportHelpModal && exportHelpModal.getAttribute('aria-hidden') === 'false') {
                    closeExportHelp();
                    return;
                }

                if (event.key === 'Escape' && uploadModal && uploadModal.getAttribute('aria-hidden') === 'false') {
                    closeUploadModal();
                    return;
                }

                if (event.key === 'Escape' && participantModal && participantModal.getAttribute('aria-hidden') === 'false') {
                    closeParticipantModal();
                    return;
                }

                if (event.key === 'Escape' && searchPanel && searchPanel.classList.contains('is-open')) {
                    closeSearchPanel();
                    return;
                }

                if (viewer.getAttribute('aria-hidden') === 'true') {
                    return;
                }

                if (event.key === 'Escape') {
                    closeViewer();
                }

                if (event.key === 'ArrowRight' && currentMediaIndex < mediaGallery.length - 1) {
                    event.preventDefault();
                    showGalleryItem(currentMediaIndex + 1);
                }

                if (event.key === 'ArrowLeft' && currentMediaIndex > 0) {
                    event.preventDefault();
                    showGalleryItem(currentMediaIndex - 1);
                }
            });

            viewerStage.addEventListener('wheel', (event) => {
                if (viewer.getAttribute('aria-hidden') === 'true') {
                    return;
                }

                if (Math.abs(event.deltaX) < WHEEL_NAV_THRESHOLD || Math.abs(event.deltaX) <= Math.abs(event.deltaY)) {
                    return;
                }

                const now = Date.now();
                if (now - lastWheelNavigationAt < WHEEL_NAV_COOLDOWN_MS) {
                    event.preventDefault();
                    return;
                }

                const direction = event.deltaX > 0 ? 1 : -1;
                if (navigateGalleryBy(direction)) {
                    event.preventDefault();
                    lastWheelNavigationAt = now;
                }
            }, { passive: false });

            viewerStage.addEventListener('pointerdown', (event) => {
                if (viewer.getAttribute('aria-hidden') === 'true' || currentMediaKind !== 'image' || scale <= 1) {
                    return;
                }
                isDragging = true;
                startX = event.clientX;
                startY = event.clientY;
                startPanX = panX;
                startPanY = panY;
                viewerStage.setPointerCapture(event.pointerId);
            });

            viewerStage.addEventListener('pointermove', (event) => {
                if (!isDragging || currentMediaKind !== 'image' || scale <= 1) {
                    return;
                }
                panX = startPanX + (event.clientX - startX);
                panY = startPanY + (event.clientY - startY);
                applyTransform();
            });

            const endDrag = (event) => {
                if (isDragging) {
                    isDragging = false;
                    if (viewerStage.hasPointerCapture(event.pointerId)) {
                        viewerStage.releasePointerCapture(event.pointerId);
                    }
                }
            };

            viewerStage.addEventListener('pointerup', endDrag);
            viewerStage.addEventListener('pointercancel', endDrag);

            const distance = (touches) => {
                const dx = touches[0].clientX - touches[1].clientX;
                const dy = touches[0].clientY - touches[1].clientY;
                return Math.hypot(dx, dy);
            };

            viewerStage.addEventListener('touchstart', (event) => {
                if (viewer.getAttribute('aria-hidden') === 'true') {
                    return;
                }

                if (
                    event.touches.length === 1
                    && (currentMediaKind === 'image' || currentMediaKind === 'video')
                    && scale <= 1
                ) {
                    touchSwipeStartX = event.touches[0].clientX;
                    touchSwipeStartY = event.touches[0].clientY;
                }

                if (currentMediaKind === 'image' && event.touches.length === 2) {
                    initialPinchDistance = distance(event.touches);
                    initialScale = scale;
                    touchSwipeStartX = null;
                    touchSwipeStartY = null;
                    event.preventDefault();
                }
            }, { passive: false });

            viewerStage.addEventListener('touchmove', (event) => {
                if (viewer.getAttribute('aria-hidden') === 'true') {
                    return;
                }

                if (currentMediaKind === 'image' && event.touches.length === 2) {
                    if (initialPinchDistance === null) {
                        initialPinchDistance = distance(event.touches);
                        initialScale = scale;
                    }

                    const newDistance = distance(event.touches);
                    const rawRatio = newDistance / initialPinchDistance;
                    const dampedRatio = 1 + ((rawRatio - 1) * PINCH_SENSITIVITY);
                    setZoom(initialScale * dampedRatio);
                    event.preventDefault();
                    return;
                }

                if (
                    event.touches.length === 1
                    && touchSwipeStartX !== null
                    && touchSwipeStartY !== null
                    && scale <= 1
                ) {
                    const dx = event.touches[0].clientX - touchSwipeStartX;
                    const dy = event.touches[0].clientY - touchSwipeStartY;
                    if (Math.abs(dx) > Math.abs(dy)) {
                        event.preventDefault();
                    }
                }
            }, { passive: false });

            viewerStage.addEventListener('touchend', (event) => {
                if (viewer.getAttribute('aria-hidden') === 'true') {
                    return;
                }

                if (
                    event.touches.length === 0
                    && touchSwipeStartX !== null
                    && touchSwipeStartY !== null
                    && initialPinchDistance === null
                    && scale <= 1
                    && event.changedTouches.length > 0
                ) {
                    const touch = event.changedTouches[0];
                    const dx = touch.clientX - touchSwipeStartX;
                    const dy = touch.clientY - touchSwipeStartY;
                    const absX = Math.abs(dx);
                    const absY = Math.abs(dy);

                    if (absX >= SWIPE_DISTANCE_THRESHOLD && absX > (absY * SWIPE_AXIS_RATIO)) {
                        navigateGalleryBy(dx < 0 ? 1 : -1);
                    }
                }

                if (scale <= 1) {
                    panX = 0;
                    panY = 0;
                    applyTransform();
                }

                if (event.touches.length < 2) {
                    initialPinchDistance = null;
                }
                if (event.touches.length === 0) {
                    touchSwipeStartX = null;
                    touchSwipeStartY = null;
                }
            });

            viewerStage.addEventListener('touchcancel', () => {
                initialPinchDistance = null;
                touchSwipeStartX = null;
                touchSwipeStartY = null;
            });

            const blockNativePinch = (event) => {
                if (!isImageViewerOpen()) {
                    return;
                }
                event.preventDefault();
            };

            viewer.addEventListener('touchmove', (event) => {
                if (!isImageViewerOpen() || event.touches.length < 2) {
                    return;
                }
                event.preventDefault();
            }, { passive: false });

            ['gesturestart', 'gesturechange', 'gestureend'].forEach((eventName) => {
                viewerStage.addEventListener(eventName, blockNativePinch, { passive: false });
                viewer.addEventListener(eventName, blockNativePinch, { passive: false });
            });

            const ZIP_CACHE_DB_NAME = 'wa_export_viewer_cache';
            const ZIP_CACHE_STORE_NAME = 'files';
            const ZIP_CACHE_KEY = 'latest_zip';
            const PROFILE_CACHE_MY_KEY = 'latest_my_photo';
            const PROFILE_CACHE_CONTACT_KEY = 'latest_contact_photo';

            const openZipCacheDb = () => new Promise((resolve, reject) => {
                if (!('indexedDB' in window)) {
                    reject(new Error('IndexedDB niet beschikbaar.'));
                    return;
                }

                const request = window.indexedDB.open(ZIP_CACHE_DB_NAME, 1);
                request.onupgradeneeded = () => {
                    const db = request.result;
                    if (!db.objectStoreNames.contains(ZIP_CACHE_STORE_NAME)) {
                        db.createObjectStore(ZIP_CACHE_STORE_NAME, { keyPath: 'id' });
                    }
                };
                request.onsuccess = () => resolve(request.result);
                    request.onerror = () => reject(request.error || new Error('Could not open cache database.'));
            });

            const readCachedZip = async () => {
                const db = await openZipCacheDb();
                return new Promise((resolve, reject) => {
                    const transaction = db.transaction(ZIP_CACHE_STORE_NAME, 'readonly');
                    const store = transaction.objectStore(ZIP_CACHE_STORE_NAME);
                    const request = store.get(ZIP_CACHE_KEY);
                    request.onsuccess = () => resolve(request.result || null);
                    request.onerror = () => reject(request.error || new Error('Could not read cached ZIP.'));
                    transaction.oncomplete = () => db.close();
                });
            };

            const writeCachedZip = async (file) => {
                const db = await openZipCacheDb();
                return new Promise((resolve, reject) => {
                    const transaction = db.transaction(ZIP_CACHE_STORE_NAME, 'readwrite');
                    const store = transaction.objectStore(ZIP_CACHE_STORE_NAME);
                    const payload = {
                        id: ZIP_CACHE_KEY,
                        name: file.name,
                        type: file.type || 'application/zip',
                        updatedAt: Date.now(),
                        blob: file,
                    };
                    const request = store.put(payload);
                    request.onsuccess = () => resolve();
                    request.onerror = () => reject(request.error || new Error('Could not save cached ZIP.'));
                    transaction.oncomplete = () => db.close();
                });
            };

            const readCachedProfilePhoto = async (cacheKey) => {
                const db = await openZipCacheDb();
                return new Promise((resolve, reject) => {
                    const transaction = db.transaction(ZIP_CACHE_STORE_NAME, 'readonly');
                    const store = transaction.objectStore(ZIP_CACHE_STORE_NAME);
                    const request = store.get(cacheKey);
                    request.onsuccess = () => resolve(request.result || null);
                    request.onerror = () => reject(request.error || new Error('Could not read cached profile photo.'));
                    transaction.oncomplete = () => db.close();
                });
            };

            const writeCachedProfilePhoto = async (cacheKey, file) => {
                const db = await openZipCacheDb();
                return new Promise((resolve, reject) => {
                    const transaction = db.transaction(ZIP_CACHE_STORE_NAME, 'readwrite');
                    const store = transaction.objectStore(ZIP_CACHE_STORE_NAME);
                    const payload = {
                        id: cacheKey,
                        name: file.name,
                        type: file.type || 'image/jpeg',
                        updatedAt: Date.now(),
                        blob: file,
                    };
                    const request = store.put(payload);
                    request.onsuccess = () => resolve();
                    request.onerror = () => reject(request.error || new Error('Could not save profile photo.'));
                    transaction.oncomplete = () => db.close();
                });
            };

            const clearCachedZip = async () => {
                const db = await openZipCacheDb();
                return new Promise((resolve, reject) => {
                    const transaction = db.transaction(ZIP_CACHE_STORE_NAME, 'readwrite');
                    const store = transaction.objectStore(ZIP_CACHE_STORE_NAME);
                    const request = store.delete(ZIP_CACHE_KEY);
                    request.onsuccess = () => resolve();
                    request.onerror = () => reject(request.error || new Error('Could not remove cached ZIP.'));
                    transaction.oncomplete = () => db.close();
                });
            };

            const processZipFile = async (zipFile, options = {}) => {
                const { persist = true } = options;
                const parsed = await parseZipExport(zipFile);
                const previousUrls = activeDynamicObjectUrls;
                const hasOwnerPhoto = !!(myPhotoInput && myPhotoInput.files && myPhotoInput.files[0]);
                const hasContactPhoto = !!(contactPhotoInput && contactPhotoInput.files && contactPhotoInput.files[0]);

                try {
                    activeDynamicObjectUrls = parsed.objectUrls;
                    applyChatModel(parsed);
                    if (hasOwnerPhoto) {
                        applyProfilePhotoSelection('owner', true);
                    }
                    if (hasContactPhoto) {
                        applyProfilePhotoSelection('contact', true);
                    }
                    revokeObjectUrls(previousUrls);
                } catch (renderError) {
                    revokeObjectUrls(parsed.objectUrls);
                    activeDynamicObjectUrls = previousUrls;
                    throw renderError;
                }

                if (persist) {
                    try {
                        await writeCachedZip(zipFile);
                    } catch (cacheError) {
                        // Keep chat loading functional even when browser storage is unavailable.
                    }
                }

                markUploadOnboardingSeen();
                closeUploadModal();

                const appliedPhotos = [];
                if (hasOwnerPhoto) {
                    appliedPhotos.push('your photo');
                }
                if (hasContactPhoto) {
                    appliedPhotos.push('contact photo');
                }
                const suffix = appliedPhotos.length > 0 ? ` (with ${appliedPhotos.join(' and ')})` : '';
                return `Loaded: ${zipFile.name}${suffix}`;
            };

            const loadSelectedZip = async () => {
                if (!zipUploadInput) {
                    return;
                }

                const zipFile = zipUploadInput.files && zipUploadInput.files[0];
                if (!zipFile) {
                    setUploadStatus('Choose a ZIP file first.', true);
                    return;
                }

                if (!/\.zip$/i.test(zipFile.name)) {
                    setUploadStatus('Upload a ZIP file from your WhatsApp export.', true);
                    return;
                }

                setUploadBusy(true);
                setUploadStatus('Reading ZIP locally...', false);

                try {
                    const successMessage = await processZipFile(zipFile, { persist: true });
                    setUploadStatus(successMessage, false);
                    openParticipantPickerForCurrentChat();
                } catch (error) {
                    const message = error instanceof Error ? error.message : 'ZIP could not be read.';
                    setUploadStatus(message, true);
                } finally {
                    setUploadBusy(false);
                }
            };

            if (zipUploadButton) {
                zipUploadButton.addEventListener('click', loadSelectedZip);
            }

            if (zipUploadInput) {
                zipUploadInput.addEventListener('change', () => {
                    const zipFile = zipUploadInput.files && zipUploadInput.files[0];
                    if (!zipFile) {
                        setUploadStatus('', false);
                        return;
                    }
                    setUploadStatus(`Ready to load: ${zipFile.name}`, false);
                });
            }

            if (myPhotoInput) {
                myPhotoInput.addEventListener('change', () => {
                    const file = myPhotoInput.files && myPhotoInput.files[0];
                    if (!file) {
                        setUploadStatus('', false);
                        return;
                    }
                    applyProfilePhotoSelection('owner');
                });
            }

            if (contactPhotoInput) {
                contactPhotoInput.addEventListener('change', () => {
                    const file = contactPhotoInput.files && contactPhotoInput.files[0];
                    if (!file) {
                        setUploadStatus('', false);
                        return;
                    }
                    applyProfilePhotoSelection('contact');
                });
            }

            const restoreCachedProfilePhotosOnLoad = async () => {
                try {
                    const [cachedMy, cachedContact] = await Promise.all([
                        readCachedProfilePhoto(PROFILE_CACHE_MY_KEY),
                        readCachedProfilePhoto(PROFILE_CACHE_CONTACT_KEY),
                    ]);

                    let hasChanges = false;

                    if (cachedMy && cachedMy.blob instanceof Blob) {
                        if (customMyObjectUrl) {
                            URL.revokeObjectURL(customMyObjectUrl);
                        }
                        customMyObjectUrl = URL.createObjectURL(cachedMy.blob);
                        customMyProfilePhotoUrl = customMyObjectUrl;
                        hasChanges = true;
                    }

                    if (cachedContact && cachedContact.blob instanceof Blob) {
                        if (customProfileObjectUrl) {
                            URL.revokeObjectURL(customProfileObjectUrl);
                        }
                        customProfileObjectUrl = URL.createObjectURL(cachedContact.blob);
                        customProfilePhotoUrl = customProfileObjectUrl;
                        hasChanges = true;
                    }

                    if (!hasChanges) {
                        return;
                    }

                    currentContext.profilePhotoUrl = customProfilePhotoUrl || baseProfilePhotoUrl;
                    currentContext.myProfilePhotoUrl = customMyProfilePhotoUrl || baseMyProfilePhotoUrl;
                    renderTopbarAvatar(currentContext.profilePhotoUrl);
                    if (Array.isArray(currentMessages)) {
                        renderThreadMessages(currentMessages, currentContext);
                    } else {
                        updateAudioAvatarsInDom();
                    }
                } catch (error) {
                    // Ignore profile cache restore errors.
                }
            };

            const restoreCachedZipOnLoad = async () => {
                if (typeof window.JSZip === 'undefined') {
                    return;
                }

                try {
                    const cachedZip = await readCachedZip();
                    if (!cachedZip || !cachedZip.blob) {
                        return;
                    }

                    const cachedFile = new File(
                        [cachedZip.blob],
                        cachedZip.name || 'cached-export.zip',
                        { type: cachedZip.type || 'application/zip', lastModified: cachedZip.updatedAt || Date.now() }
                    );

                    setUploadBusy(true);
                    setUploadStatus(`Restoring previous chat: ${cachedFile.name}...`, false);
                    try {
                        const successMessage = await processZipFile(cachedFile, { persist: false });
                        setUploadStatus(`${successMessage} (restored automatically)`, false);
                    } finally {
                        setUploadBusy(false);
                    }
                } catch (error) {
                    // Ignore cache restore failures (private mode / blocked storage) and continue normally.
                }
            };

            restoreCachedProfilePhotosOnLoad();
            if (shouldShowUploadOnboarding()) {
                openUploadModal(true);
            }

            if (typeof window.JSZip === 'undefined') {
                setUploadStatus('ZIP library could not be loaded. Check your internet connection and reload the page.', true);
            } else {
                restoreCachedZipOnLoad();
            }
        })();
    </script>
</body>
</html>
