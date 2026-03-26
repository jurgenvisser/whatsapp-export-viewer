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

    $months = [1 => 'jan', 'feb', 'mrt', 'apr', 'mei', 'jun', 'jul', 'aug', 'sep', 'okt', 'nov', 'dec'];
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

function detectWhatsAppNoticeType(?string $sender, string $text): ?string
{
    $normalizedText = comparableName($text);
    if ($normalizedText === '') {
        return null;
    }

    $normalizedSender = comparableName((string) $sender);
    $looksLikeSystemSender = $normalizedSender === ''
        || str_contains($normalizedSender, 'system')
        || str_contains($normalizedSender, 'whatsapp');
    if (!$looksLikeSystemSender) {
        return null;
    }

    if (
        str_contains($normalizedText, 'end to end encrypted')
        || str_contains($normalizedText, 'end to end versleuteld')
        || (str_contains($normalizedText, 'messages and calls') && str_contains($normalizedText, 'only people in this chat'))
    ) {
        return 'encryption';
    }

    $isNumberChangeEn = str_contains($normalizedText, 'changed')
        && str_contains($normalizedText, 'phone number')
        && str_contains($normalizedText, 'new number');
    $isNumberChangeNl = str_contains($normalizedText, 'nummer')
        && (str_contains($normalizedText, 'gewijzigd') || str_contains($normalizedText, 'veranderd'))
        && str_contains($normalizedText, 'nieuw');

    if ($isNumberChangeEn || $isNumberChangeNl) {
        return 'number_change';
    }

    return null;
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

$headerTitle = is_string($settings['title']) && $settings['title'] !== '' ? $settings['title'] : $folderTitle;
$profilePhotoUrl = findProfilePhoto(is_string($settings['profile_photo']) ? $settings['profile_photo'] : null, $chatDir);
$myProfilePhotoUrl = findOwnProfilePhoto();
$backgroundPhotoUrl = findBackgroundPhoto();
$composerPlaceholder = readTextFileOrFallback(__DIR__ . '/composer_placeholder.txt', 'chat in herinnering');
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
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
    <title><?= e($headerTitle) ?> - WhatsApp herinnering</title>
    <style>
        :root {
            --wa-header: #075e54;
            --wa-bg: #efeae2;
            --wa-outgoing: #d9fdd3;
            --wa-incoming: #ffffff;
            --wa-chat-text: #111b21;
            --wa-meta-text: #6e7478;
            --wa-date-bg: rgba(247, 248, 249, 0.94);
            --wa-shadow: 0 1px 1px rgba(0, 0, 0, 0.08), 0 1px 3px rgba(0, 0, 0, 0.12);
            --wa-page-max: 880px;
            --wa-topbar-height: 62px;
            --wa-composer-height: 76px;
        }

        * {
            box-sizing: border-box;
        }

        html,
        body {
            margin: 0;
            padding: 0;
            min-height: 100%;
            font-family: -apple-system, BlinkMacSystemFont, "SF Pro Text", "Helvetica Neue", Helvetica, Arial, sans-serif;
            color: var(--wa-chat-text);
        }

        body {
            position: relative;
            background-color: var(--wa-bg);
            overflow-x: hidden;
        }

        * {
            scrollbar-width: thin;
            scrollbar-color: #075e54 transparent;
        }

        *::-webkit-scrollbar {
            width: 8px;
            height: 8px;
            background: transparent;
        }

        *::-webkit-scrollbar-track {
            background: transparent;
            border: 0;
        }

        *::-webkit-scrollbar-thumb {
            background: #075e54;
            border-radius: 999px;
            border: 0;
        }

        .chat-bg {
            position: fixed;
            inset: 0;
            z-index: -1;
            background-color: #e5ddd5;
            background-image:
                linear-gradient(rgba(229, 221, 213, 0.94), rgba(229, 221, 213, 0.94)),
                url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='160' height='160' viewBox='0 0 40 40'%3E%3Cg fill='%23d6d3cd' fill-opacity='0.55'%3E%3Cpath d='M20 2l2 2-2 2-2-2 2-2zm8 8l2 2-2 2-2-2 2-2zm-16 0l2 2-2 2-2-2 2-2zm8 8l2 2-2 2-2-2 2-2zm8 8l2 2-2 2-2-2 2-2zm-16 0l2 2-2 2-2-2 2-2z'/%3E%3C/g%3E%3C/svg%3E");
            background-repeat: repeat;
            background-size: 240px 240px;
        }

        .chat-bg.has-custom-background {
            background-repeat: no-repeat;
            background-position: center center;
            background-size: auto 100%;
        }

        @media (min-aspect-ratio: 1 / 1) {
            .chat-bg.has-custom-background {
                background-size: 100% auto;
            }
        }

        .app {
            max-width: var(--wa-page-max);
            margin: 0 auto;
            min-height: 100dvh;
            display: flex;
            flex-direction: column;
            background: transparent;
        }

        .topbar {
            position: -webkit-sticky;
            position: sticky;
            top: 0;
            z-index: 20;
            background: var(--wa-header);
            color: #fff;
            min-height: var(--wa-topbar-height);
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 14px;
            box-shadow: 0 1px 0 rgba(0, 0, 0, 0.1);
        }

        .avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            overflow: hidden;
            background: #cfd8dc;
            display: grid;
            place-items: center;
            flex: 0 0 auto;
            font-size: 18px;
            font-weight: 600;
            color: #2f3b43;
        }

        .avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .topbar-meta {
            min-width: 0;
        }

        .topbar-title {
            font-size: 17px;
            line-height: 1.2;
            font-weight: 600;
            margin: 0;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .topbar-sub {
            font-size: 12px;
            line-height: 1.2;
            opacity: 0.9;
            margin-top: 2px;
        }

        .thread {
            flex: 1;
            padding: 4px 18px calc(var(--wa-composer-height) + env(safe-area-inset-bottom, 0px) + 10px);
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .composer-shell {
            position: fixed;
            left: 0;
            right: 0;
            bottom: 0;
            z-index: 24;
            display: flex;
            justify-content: center;
        }

        .composer {
            width: min(100%, var(--wa-page-max));
            display: flex;
            align-items: flex-end;
            gap: 8px;
            padding: 6px 8px calc(env(safe-area-inset-bottom, 0px) + 8px);
            background: linear-gradient(to top, rgba(234, 221, 228, 0.96), rgba(234, 221, 228, 0.8));
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
        }

        .composer-input-wrap {
            flex: 1;
            min-width: 0;
            display: flex;
            align-items: center;
            gap: 10px;
            min-height: 42px;
            padding: 0 14px;
            border-radius: 22px;
            background: rgba(255, 255, 255, 0.98);
            box-shadow: 0 1px 0 rgba(0, 0, 0, 0.08);
        }

        .composer-placeholder {
            flex: 1;
            min-width: 0;
            font-size: 15px;
            line-height: 1.25;
            color: #79858d;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .composer-icon {
            width: 42px;
            height: 42px;
            padding: 0;
            border: 0;
            border-radius: 50%;
            background: transparent;
            color: #111b21;
            display: grid;
            place-items: center;
            flex: 0 0 auto;
        }

        .composer-icon svg {
            width: 26px;
            height: 26px;
            display: block;
        }

        .composer-plus svg {
            width: 32px;
            height: 32px;
        }

        .date-chip {
            align-self: center;
            font-size: 12px;
            color: #54656f;
            background: var(--wa-date-bg);
            border-radius: 8px;
            padding: 6px 10px;
            box-shadow: 0 1px 0 rgba(0, 0, 0, 0.08);
            margin: 18px 0 2px;
        }

        .msg-row {
            display: flex;
        }

        .msg-row.incoming {
            justify-content: flex-start;
            padding-right: 10px;
        }

        .msg-row.outgoing {
            justify-content: flex-end;
            padding-left: 10px;
        }

        .msg-row.system {
            justify-content: center;
        }

        .bubble {
            max-width: min(85vw, 440px);
            border-radius: 12px;
            padding: 6px 8px 4px;
            position: relative;
            box-shadow: var(--wa-shadow);
            overflow-wrap: break-word;
            word-break: break-word;
            font-size: 12px;
            line-height: 1.35;
        }

        .incoming .bubble {
            background: var(--wa-incoming);
            border-bottom-left-radius: 14px;
            margin-right: 20vw;
        }

        .outgoing .bubble {
            background: var(--wa-outgoing);
            border-bottom-right-radius: 14px;
            margin-left: 20vw;
        }

        .incoming .bubble::before,
        .outgoing .bubble::before {
            content: "";
            position: absolute;
            top: auto;
            bottom: 0;
            width: 12px;
            height: 14px;
            background: inherit;
        }

        .incoming .bubble::before {
            left: -7px;
            clip-path: polygon(100% 0, 100% 100%, 0 100%);
            border-bottom-right-radius: 8px;
        }

        .outgoing .bubble::before {
            right: -7px;
            clip-path: polygon(0 0, 100% 100%, 0 100%);
            border-bottom-left-radius: 8px;
        }

        .bubble.has-media {
            padding: 4px 4px 3px;
        }

        .bubble.has-media .bubble-text {
            padding: 2px 4px 0;
        }

        .bubble.media-image.has-media,
        .bubble.media-video.has-media {
            padding: 4px;
        }

        .bubble.media-image .attachment,
        .bubble.media-video .attachment {
            margin-top: 0;
        }

        .bubble.media-image .bubble-text,
        .bubble.media-video .bubble-text {
            padding: 7px 4px 1px;
        }

        .bubble.media-audio {
            padding: 8px 8px 4px;
        }

        .bubble.media-video {
            max-width: min(88vw, 500px);
        }

        .system .bubble {
            max-width: min(90vw, 560px);
            background: rgba(245, 245, 245, 0.92);
            color: #5f6368;
            font-size: 12px;
            border-radius: 12px;
            padding: 6px 10px;
        }

        .msg-row.system-notice {
            justify-content: center;
        }

        .notice-card {
            max-width: min(72vw, 520px);
            border-radius: 14px;
            padding: 10px 14px;
            box-shadow: var(--wa-shadow);
            color: #111b21;
        }

        .notice-card.notice-encryption {
            background: rgba(244, 232, 204, 0.96);
        }

        .notice-card.notice-number_change {
            background: rgba(243, 245, 246, 0.94);
        }

        .notice-content {
            display: flex;
            align-items: flex-start;
            gap: 8px;
        }

        .notice-icon {
            width: 18px;
            height: 18px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #1f2c33;
            flex: 0 0 auto;
            margin-top: 2px;
        }

        .notice-icon svg {
            width: 100%;
            height: 100%;
            display: block;
        }

        .notice-text {
            font-size: 11.6px;
            line-height: 1.35;
            color: #111b21;
        }

        .notice-card.notice-encryption .notice-content {
            justify-content: center;
        }

        .notice-card.notice-encryption .notice-text {
            text-align: center;
        }

        .bubble-text a {
            color: #0b66c3;
            text-decoration: none;
            word-break: break-all;
        }

        .bubble-text a:hover {
            text-decoration: underline;
        }

        .bubble-text-inline {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 8px;
        }

        .bubble-inline-main {
            min-width: 0;
        }

        .bubble-meta-inline {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            margin-left: 8px;
            flex: 0 0 auto;
            font-size: 11px;
            color: var(--wa-meta-text);
            line-height: 1;
            white-space: nowrap;
            padding-bottom: 1px;
        }

        .bubble-meta {
            margin-top: 2px;
            display: flex;
            justify-content: flex-end;
            align-items: center;
            gap: 4px;
            font-size: 11px;
            color: var(--wa-meta-text);
            min-height: 12px;
        }

        .status-checks {
            display: inline-flex;
            align-items: center;
            color: #34b7f1;
        }

        .status-checks svg {
            display: block;
            width: 16px;
            height: 11px;
        }

        .edited {
            font-style: italic;
            font-size: 11px;
            color: #6a7e88;
        }

        .omitted {
            margin-top: 6px;
            font-size: 12px;
            color: #54656f;
            padding: 5px 7px;
            border-radius: 6px;
            background: rgba(235, 239, 241, 0.7);
        }

        .attachment {
            margin-top: 6px;
        }

        .image-button {
            border: 0;
            margin: 0;
            padding: 0;
            width: 100%;
            background: transparent;
            display: block;
            border-radius: 10px;
            overflow: hidden;
            cursor: zoom-in;
        }

        .image-button img {
            display: block;
            width: 100%;
            max-height: min(66vw, 360px);
            object-fit: cover;
            border-radius: 10px;
        }

        .video-preview-button {
            border: 0;
            margin: 0;
            padding: 0;
            width: 100%;
            display: block;
            position: relative;
            background: #000;
            border-radius: 10px;
            overflow: hidden;
            cursor: pointer;
        }

        .video-preview-media {
            display: block;
            width: 100%;
            max-height: min(84vw, 560px);
            object-fit: contain;
            background: #000;
            border-radius: 10px;
            pointer-events: none;
        }

        .video-play-badge,
        .viewer-video-play {
            position: absolute;
            inset: 50% auto auto 50%;
            transform: translate(-50%, -50%);
            width: 76px;
            height: 76px;
            border: 0;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.86);
            box-shadow: 0 10px 24px rgba(0, 0, 0, 0.25);
            display: grid;
            place-items: center;
            cursor: pointer;
        }

        .video-play-badge::before,
        .viewer-video-play::before {
            content: "";
            width: 0;
            height: 0;
            margin-left: 5px;
            border-top: 15px solid transparent;
            border-bottom: 15px solid transparent;
            border-left: 24px solid rgba(17, 27, 33, 0.7);
        }

        .video-preview-meta {
            position: absolute;
            inset: auto 0 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            padding: 14px 10px 9px;
            background: linear-gradient(to top, rgba(0, 0, 0, 0.62), rgba(0, 0, 0, 0.2) 62%, rgba(0, 0, 0, 0));
            color: #fff;
            pointer-events: none;
        }

        .video-preview-left,
        .video-preview-right {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            min-width: 0;
        }

        .video-preview-icon {
            width: 15px;
            height: 15px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            flex: 0 0 auto;
        }

        .video-preview-icon svg {
            width: 100%;
            height: 100%;
            display: block;
        }

        .video-preview-duration,
        .video-preview-time {
            font-size: 12px;
            line-height: 1;
            font-weight: 500;
            text-shadow: 0 1px 2px rgba(0, 0, 0, 0.44);
            white-space: nowrap;
        }

        .video-preview-status {
            display: inline-flex;
            align-items: center;
            color: #34b7f1;
            flex: 0 0 auto;
        }

        .video-preview-status svg {
            width: 14px;
            height: 10px;
            display: block;
        }

        .video-duration {
            position: absolute;
            left: 8px;
            bottom: 8px;
            padding: 4px 6px;
            border-radius: 10px;
            background: rgba(0, 0, 0, 0.58);
            color: #fff;
            font-size: 12px;
            line-height: 1;
            font-weight: 500;
        }

        .video-play-badge {
            pointer-events: none;
        }

        .audio-card {
            display: flex;
            align-items: center;
            gap: 9px;
            min-width: min(72vw, 320px);
        }

        .audio-card.incoming-audio .audio-toggle {
            order: 1;
        }

        .audio-card.incoming-audio .audio-track {
            order: 2;
        }

        .audio-card.incoming-audio .audio-avatar {
            order: 3;
        }

        .audio-card.outgoing-audio .audio-avatar {
            order: 1;
        }

        .audio-card.outgoing-audio .audio-toggle {
            order: 2;
        }

        .audio-card.outgoing-audio .audio-track {
            order: 3;
        }

        .audio-avatar {
            position: relative;
            width: 54px;
            height: 54px;
            flex: 0 0 54px;
        }

        .audio-avatar-image,
        .audio-avatar-fallback {
            display: grid;
            place-items: center;
            width: 100%;
            height: 100%;
            border-radius: 50%;
            overflow: hidden;
        }

        .audio-avatar-image {
            object-fit: cover;
            background: #d3d8dc;
        }

        .audio-avatar-fallback {
            background: #cad2d9;
            color: #23343b;
            font-size: 18px;
            font-weight: 600;
            line-height: 1;
        }

        .outgoing .audio-avatar-fallback {
            background: #a8c7a1;
        }

        .audio-avatar-badge {
            position: absolute;
            bottom: -5px;
            width: 40%;
            height: 40%;
            min-width: 20px;
            min-height: 20px;
            max-width: 22px;
            max-height: 22px;
            display: grid;
            place-items: center;
            filter: drop-shadow(0 1px 1px rgba(0, 0, 0, 0.25));
            color: #25d366;
            background: transparent;
            border: 0;
        }

        .audio-card.incoming-audio .audio-avatar-badge {
            left: -10px;
        }

        .audio-card.outgoing-audio .audio-avatar-badge {
            right: -10px;
            color: #5f6d64;
        }

        .audio-avatar-badge svg {
            width: 100%;
            height: 100%;
            display: block;
            overflow: visible;
            filter:
                drop-shadow(0 0 0.85px rgba(255, 255, 255, 0.98))
                drop-shadow(0 0 0.85px rgba(255, 255, 255, 0.98));
        }

        .audio-toggle {
            width: 34px;
            height: 34px;
            border: 0;
            border-radius: 0;
            position: relative;
            background: transparent;
            color: #667781;
            flex: 0 0 auto;
            box-shadow: none;
            cursor: pointer;
            padding: 0;
            -webkit-appearance: none;
            appearance: none;
        }

        .outgoing .audio-toggle {
            background: transparent;
            color: #667781;
        }

        .audio-toggle-icon {
            position: absolute;
            inset: 0;
            margin: auto;
            width: 18px;
            height: 18px;
            display: block;
        }

        .audio-toggle-icon::before {
            content: "";
            position: absolute;
            top: 1px;
            left: 2px;
            width: 14px;
            height: 16px;
            background: currentColor;
            border-radius: 2px;
            clip-path: polygon(0 0, 100% 50%, 0 100%);
        }

        .audio-card.is-playing .audio-toggle-icon::before {
            top: 1px;
            left: 2px;
            width: 4px;
            height: 16px;
            border-radius: 1px;
            clip-path: none;
            background: currentColor;
            box-shadow: 8px 0 0 currentColor;
        }

        .audio-track {
            position: relative;
            flex: 1;
            min-width: 0;
            --progress: 0%;
            --wave-h: 36px;
            --played: #101317;
            --unplayed: #c5c9cc;
            --dot: #25d366;
        }

        .outgoing .audio-track {
            --unplayed: #9eba9a;
            --dot: #111b21;
        }

        .audio-waveform {
            height: var(--wave-h);
            width: 100%;
            display: grid;
            grid-template-columns: repeat(var(--bars, 44), minmax(0, 1fr));
            align-items: center;
            gap: 2px;
            padding-top: 2px;
        }

        .audio-bar {
            width: 100%;
            justify-self: center;
            border-radius: 999px;
            height: calc(var(--h, 12) * 1px);
            background: var(--unplayed);
            transition: background-color 0.12s linear;
        }

        .audio-bar.is-played {
            background: var(--played);
        }

        .audio-thumb {
            position: absolute;
            left: var(--progress);
            top: calc((var(--wave-h) / 2) - 7px);
            width: 14px;
            height: 14px;
            margin-left: -7px;
            border-radius: 50%;
            background: var(--dot);
            box-shadow: 0 0 0 2px rgba(255, 255, 255, 0.88);
            pointer-events: none;
        }

        .outgoing .audio-thumb {
            box-shadow: 0 0 0 2px rgba(217, 253, 211, 0.96);
        }

        .audio-range {
            position: absolute;
            left: 0;
            right: 0;
            top: 0;
            height: var(--wave-h);
            width: 100%;
            margin: 0;
            opacity: 0;
            cursor: pointer;
        }

        .audio-duration {
            display: block;
            margin-top: 4px;
            font-size: 11px;
            line-height: 1;
            color: var(--wa-meta-text);
        }

        .audio-element {
            display: none;
        }

        .file-link {
            display: inline-block;
            font-size: 13px;
            text-decoration: none;
            color: #0b66c3;
            background: rgba(255, 255, 255, 0.65);
            border: 1px solid rgba(11, 102, 195, 0.25);
            border-radius: 7px;
            padding: 6px 8px;
        }

        .viewer {
            position: fixed;
            inset: 0;
            background: rgba(17, 27, 33, 0.94);
            z-index: 100;
            display: none;
            flex-direction: column;
            overscroll-behavior: contain;
        }

        .viewer[aria-hidden="false"] {
            display: flex;
        }

        .viewer-controls {
            display: flex;
            align-items: center;
            justify-content: flex-start;
            gap: 8px;
            padding: 12px;
        }

        .viewer-buttons {
            display: flex;
            gap: 8px;
        }

        .viewer button {
            border: 0;
            border-radius: 8px;
            padding: 8px 12px;
            font-size: 14px;
            font-weight: 600;
            color: #fff;
            background: rgba(255, 255, 255, 0.14);
            cursor: pointer;
        }

        .viewer button:hover {
            background: rgba(255, 255, 255, 0.22);
        }

        .viewer-stage {
            position: relative;
            flex: 1;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 10px;
        }

        .viewer-stage.is-image {
            touch-action: none;
        }

        .viewer-stage.is-image * {
            touch-action: none;
        }

        .viewer-stage.is-video {
            touch-action: auto;
        }

        .viewer-image {
            max-width: 100%;
            max-height: 100%;
            transform-origin: center center;
            user-select: none;
            -webkit-user-drag: none;
            pointer-events: none;
            will-change: transform;
        }

        .viewer-video {
            width: 100%;
            max-width: min(92vw, 720px);
            max-height: min(72vh, 760px);
            border-radius: 12px;
            background: #000;
            display: none;
        }

        .viewer-image[hidden],
        .viewer-video[hidden] {
            display: none;
        }

        .viewer-video.is-visible {
            display: block;
        }

        .viewer-video-play {
            display: none;
            z-index: 2;
        }

        .viewer-video-play.is-visible {
            display: grid;
        }

        .viewer-strip {
            flex: 0 0 auto;
            display: flex;
            gap: 8px;
            padding: 10px max(12px, calc(50vw - 29px)) calc(14px + env(safe-area-inset-bottom, 0px));
            overflow-x: auto;
            overscroll-behavior-x: contain;
            scroll-snap-type: x mandatory;
            scroll-behavior: smooth;
            background: rgba(16, 17, 18, 0.6);
        }

        .viewer-strip::-webkit-scrollbar {
            height: 6px;
            background: transparent;
        }

        .viewer-strip::-webkit-scrollbar-track {
            background: transparent;
        }

        .viewer-strip::-webkit-scrollbar-thumb {
            background: #25d366;
            border-radius: 999px;
        }

        .viewer-thumb {
            border: 0;
            padding: 0;
            width: 58px;
            height: 58px;
            border-radius: 8px;
            overflow: hidden;
            background: rgba(255, 255, 255, 0.12);
            position: relative;
            flex: 0 0 auto;
            scroll-snap-align: center;
            cursor: pointer;
            box-shadow: inset 0 0 0 2px transparent;
        }

        .viewer-thumb.is-active {
            box-shadow: inset 0 0 0 2px #25d366;
        }

        .viewer-thumb img,
        .viewer-thumb video {
            display: block;
            width: 100%;
            height: 100%;
            object-fit: cover;
            pointer-events: none;
        }

        .viewer-thumb .video-play-badge {
            width: 28px;
            height: 28px;
        }

        .viewer-thumb .video-play-badge::before {
            margin-left: 2px;
            border-top-width: 6px;
            border-bottom-width: 6px;
            border-left-width: 10px;
        }

        .viewer-thumb .video-duration {
            left: 5px;
            bottom: 5px;
            padding: 2px 4px;
            font-size: 10px;
        }

        .empty-state {
            margin: 26px auto;
            padding: 14px;
            width: min(92vw, 580px);
            background: rgba(255, 255, 255, 0.92);
            border-radius: 10px;
            box-shadow: var(--wa-shadow);
            font-size: 14px;
        }

        @media (min-width: 900px) {
            body {
                background: #d1d7db;
            }

            .app {
                min-height: 100dvh;
            }

            .thread {
                padding: 4px 30px calc(var(--wa-composer-height) + env(safe-area-inset-bottom, 0px) + 12px);
            }

            .bubble {
                max-width: 440px;
            }

            .image-button img {
                max-height: 280px;
            }

            .video-preview-media {
                max-height: 320px;
            }
        }
    </style>
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
                    <img src="<?= e($profilePhotoUrl) ?>" alt="Profielfoto">
                <?php else: ?>
                    <?= e(firstInitial($headerTitle)) ?>
                <?php endif; ?>
            </div>
            <div class="topbar-meta">
                <h1 class="topbar-title"><?= e($headerTitle) ?></h1>
                <div class="topbar-sub">WhatsApp herinnering</div>
            </div>
        </header>

        <section class="thread">
            <?php if (!$chatFile): ?>
                <div class="empty-state">
                    Geen <code>_chat.txt</code> gevonden in deze map. Zet de WhatsApp-exportmap in deze projectmap.
                </div>
            <?php elseif ($messages === []): ?>
                <div class="empty-state">
                    Chatbestand gevonden, maar er konden geen berichten worden ingelezen.
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
                    $noticeType = detectWhatsAppNoticeType(
                        is_string($message['sender']) ? $message['sender'] : null,
                        $messageText
                    );
                    $noticeIconSvg = $noticeType === 'encryption' ? $iconLockSvg : $iconCircleUserRoundSvg;
                    ?>
                    <?php if ($noticeType !== null && $attachment === null): ?>
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
                                            aria-label="Vergroot afbeelding"
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
                                            <video class="video-preview-media" muted playsinline preload="auto" data-preview-video>
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
                                        $voiceInitial = $isOutgoingAudio ? firstInitial($ownerName) : firstInitial($contactName);
                                        ?>
                                        <div class="<?= e($audioCardClass) ?>" data-audio-card>
                                            <div class="audio-avatar" aria-hidden="true">
                                                <?php if ($voiceAvatarUrl !== null): ?>
                                                    <img class="audio-avatar-image" src="<?= e($voiceAvatarUrl) ?>" alt="">
                                                <?php else: ?>
                                                    <span class="audio-avatar-fallback"><?= e($voiceInitial) ?></span>
                                                <?php endif; ?>
                                                <span class="audio-avatar-badge">
                                                    <?= $iconMicFilledSvg ?>
                                                </span>
                                            </div>
                                            <button type="button" class="audio-toggle" data-audio-toggle aria-label="Speel spraakbericht">
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
                                                    aria-label="Spoel spraakbericht"
                                                >
                                                <div class="audio-duration" data-audio-duration>0:00</div>
                                            </div>
                                            <audio class="audio-element" data-audio preload="metadata">
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
                                <div class="omitted">Bestand niet gevonden: <?= e($message['attachment']) ?></div>
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
                                    <?= e(ucfirst((string) $message['media_omitted'])) ?> niet meegeexporteerd.
                                </div>
                            <?php endif; ?>

                            <?php if ($message['type'] === 'message' && !$hasVideoAttachment && !$showInlineMetaWithText): ?>
                                <div class="bubble-meta">
                                    <?php if ($message['edited'] === true): ?>
                                        <span class="edited">bewerkt</span>
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

    <div class="composer-shell" aria-hidden="true">
        <div class="composer">
            <button type="button" class="composer-icon composer-plus" tabindex="-1">
                <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                    <path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/>
                </svg>
            </button>
            <div class="composer-input-wrap">
                <span class="composer-placeholder"><?= e($composerPlaceholder) ?></span>
            </div>
            <button type="button" class="composer-icon" tabindex="-1">
                <?= $iconCameraSvg ?>
            </button>
            <button type="button" class="composer-icon" tabindex="-1">
                <?= $iconMicSvg ?>
            </button>
        </div>
    </div>

    <aside class="viewer" id="viewer" aria-hidden="true">
        <div class="viewer-controls">
            <button type="button" id="viewerClose">Sluit</button>
        </div>
        <div class="viewer-stage" id="viewerStage">
            <img class="viewer-image" id="viewerImage" src="" alt="" hidden>
            <video class="viewer-video" id="viewerVideo" playsinline controls preload="metadata" hidden></video>
            <button type="button" class="viewer-video-play" id="viewerVideoPlay" aria-label="Speel video"></button>
        </div>
        <div class="viewer-strip" id="viewerStrip"></div>
    </aside>

    <script>
        (() => {
            const mediaGallery = <?= $galleryJson !== false ? $galleryJson : '[]' ?>;
            const viewer = document.getElementById('viewer');
            const viewerImage = document.getElementById('viewerImage');
            const viewerVideo = document.getElementById('viewerVideo');
            const viewerVideoPlay = document.getElementById('viewerVideoPlay');
            const viewerStage = document.getElementById('viewerStage');
            const viewerStrip = document.getElementById('viewerStrip');
            const closeButton = document.getElementById('viewerClose');

            const MIN_ZOOM = 1;
            const MAX_ZOOM = 6;
            const PINCH_SENSITIVITY = 0.56;
            const SWIPE_DISTANCE_THRESHOLD = 52;
            const SWIPE_AXIS_RATIO = 1.2;
            const WHEEL_NAV_THRESHOLD = 34;
            const WHEEL_NAV_COOLDOWN_MS = 240;
            let activeAudio = null;
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

            const applyTransform = () => {
                if (currentMediaKind !== 'image') {
                    return;
                }

                viewerImage.style.transform = `translate(${panX}px, ${panY}px) scale(${scale})`;
            };

            const clampScale = (value) => Math.min(MAX_ZOOM, Math.max(MIN_ZOOM, value));
            const formatSeconds = (value) => {
                if (!Number.isFinite(value) || value < 0) {
                    return '0:00';
                }

                const minutes = Math.floor(value / 60);
                const seconds = Math.floor(value % 60);
                return `${minutes}:${String(seconds).padStart(2, '0')}`;
            };

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
                    thumbButton.setAttribute('aria-label', item.kind === 'video' ? 'Open video' : 'Open afbeelding');

                    if (item.kind === 'video') {
                        const thumbVideo = document.createElement('video');
                        thumbVideo.className = 'video-preview-media';
                        thumbVideo.muted = true;
                        thumbVideo.playsInline = true;
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

            const closeViewer = () => {
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
            };

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

                buildAudioWaveform(card);

                const progress = audio.duration ? (audio.currentTime / audio.duration) * 100 : 0;
                const safeProgress = Number.isFinite(progress) ? progress : 0;
                range.value = String(safeProgress);
                track.style.setProperty('--progress', `${safeProgress}%`);
                duration.textContent = formatSeconds(audio.duration);

                const bars = waveform.querySelectorAll('.audio-bar');
                const playedBars = Math.max(0, Math.min(
                    bars.length,
                    Math.round((safeProgress / 100) * bars.length)
                ));
                bars.forEach((bar, index) => {
                    bar.classList.toggle('is-played', index < playedBars);
                });
            };

            const pauseAudioCard = (card, reset = false) => {
                const audio = card.querySelector('[data-audio]');
                if (!audio) {
                    return;
                }

                audio.pause();
                if (reset) {
                    audio.currentTime = 0;
                }

                card.classList.remove('is-playing');
                syncAudioCard(card);

                if (activeAudio === audio) {
                    activeAudio = null;
                }
            };

            document.querySelectorAll('[data-audio-card]').forEach((card) => {
                const audio = card.querySelector('[data-audio]');
                const toggle = card.querySelector('[data-audio-toggle]');
                const range = card.querySelector('[data-audio-range]');

                if (!audio || !toggle || !range) {
                    return;
                }

                syncAudioCard(card);

                toggle.addEventListener('click', async () => {
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

                    const nextTime = (Number(range.value) / 100) * audio.duration;
                    audio.currentTime = nextTime;
                    syncAudioCard(card);
                });

                audio.addEventListener('loadedmetadata', () => syncAudioCard(card));
                audio.addEventListener('timeupdate', () => syncAudioCard(card));
                audio.addEventListener('play', () => {
                    card.classList.add('is-playing');
                    activeAudio = audio;
                    syncAudioCard(card);
                });
                audio.addEventListener('pause', () => {
                    card.classList.remove('is-playing');
                    syncAudioCard(card);
                    if (activeAudio === audio) {
                        activeAudio = null;
                    }
                });
                audio.addEventListener('ended', () => pauseAudioCard(card, true));
            });

            document.querySelectorAll('[data-preview-video]').forEach((video) => {
                primeVideoPreview(video);
            });

            document.querySelectorAll('.image-button[data-gallery-index], .video-preview-button[data-gallery-index]').forEach((button) => {
                button.addEventListener('click', () => {
                    const index = Number(button.getAttribute('data-gallery-index'));
                    openViewer(index);
                });
            });

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
        })();
    </script>
</body>
</html>
