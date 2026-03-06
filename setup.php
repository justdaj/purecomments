<?php
declare(strict_types=1);

require __DIR__ . '/includes/url.php';

$configPath = __DIR__ . '/config.php';
require __DIR__ . '/includes/config_builder.php';
$errors = [];
$saved = false;

if (is_file($configPath)) {
    header('Location: ' . pc_url('/'), true, 302);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $adminUsername = trim((string)($_POST['admin_username'] ?? ''));
    $adminPassword = (string)($_POST['admin_password'] ?? '');
    $adminPasswordConfirm = (string)($_POST['admin_password_confirm'] ?? '');
    $spamChallengeQuestion = trim((string)($_POST['spam_challenge_question'] ?? ''));
    $spamChallengeAnswer = trim((string)($_POST['spam_challenge_answer'] ?? ''));
    $spamChallengePlaceholder = trim((string)($_POST['spam_challenge_placeholder'] ?? ''));
    $postBaseUrl = trim((string)($_POST['post_base_url'] ?? ''));
    $authorName = trim((string)($_POST['author_name'] ?? ''));
    $authorEmail = trim((string)($_POST['author_email'] ?? ''));
    $notifyEmail = trim((string)($_POST['notify_email'] ?? ''));
    $moderationBaseUrl = trim((string)($_POST['moderation_base_url'] ?? ''));
    $timezone = trim((string)($_POST['timezone'] ?? default_comments_timezone()));
    $dateFormat = trim((string)($_POST['date_format'] ?? default_comments_date_format()));
    $emailProvider = trim((string)($_POST['email_provider'] ?? ''));
    if ($emailProvider === 'ses') {
        $awsRegion = trim((string)($_POST['aws_region'] ?? ''));
        $awsAccessKey = trim((string)($_POST['aws_access_key'] ?? ''));
        $awsSecretKey = trim((string)($_POST['aws_secret_key'] ?? ''));
        $sourceEmail = trim((string)($_POST['source_email'] ?? ''));
        $sourceName = trim((string)($_POST['source_name'] ?? ''));
        $smtpHost = '';
        $smtpPort = '587';
        $smtpUser = '';
        $smtpPwd = '';
        $smtpEnc = 'tls';
    } elseif ($emailProvider === 'smtp') {
        $smtpHost = trim((string)($_POST['smtp_host'] ?? ''));
        $smtpPort = trim((string)($_POST['smtp_port'] ?? '587'));
        $smtpUser = trim((string)($_POST['smtp_user'] ?? ''));
        $smtpPwd = trim((string)($_POST['smtp_pwd'] ?? ''));
        $smtpEnc = trim((string)($_POST['smtp_enc'] ?? 'tls'));
        $awsRegion = '';
        $awsAccessKey = '';
        $awsSecretKey = '';
        $sourceEmail = '';
        $sourceName = '';
    } else {
        $awsRegion = '';
        $awsAccessKey = '';
        $awsSecretKey = '';
        $sourceEmail = '';
        $sourceName = '';
        $smtpHost = '';
        $smtpPort = '587';
        $smtpUser = '';
        $smtpPwd = '';
        $smtpEnc = 'tls';
    }

    if ($adminUsername === '') {
        $errors[] = 'Admin username is required.';
    }
    if (strlen($adminPassword) < 10) {
        $errors[] = 'Admin password must be at least 10 characters.';
    }
    if (!hash_equals($adminPassword, $adminPasswordConfirm)) {
        $errors[] = 'Admin passwords do not match.';
    }
    if ($spamChallengeQuestion === '') {
        $errors[] = 'Spam challenge question is required.';
    }
    if ($spamChallengeAnswer === '') {
        $errors[] = 'Spam challenge answer is required.';
    }
    if ($authorName === '') {
        $errors[] = 'Author name is required.';
    }
    if (!filter_var($authorEmail, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Author email must be valid.';
    }
    if (!filter_var($notifyEmail, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Notification email must be valid.';
    }
    if ($postBaseUrl === '' || !filter_var($postBaseUrl, FILTER_VALIDATE_URL)) {
        $errors[] = 'Post base URL must be valid (e.g. https://example.com/blog).';
    }
    if ($moderationBaseUrl === '' || !filter_var($moderationBaseUrl, FILTER_VALIDATE_URL)) {
        $errors[] = 'Moderation base URL must be valid (e.g. https://comments.example.com).';
    }
    if (!is_valid_timezone_id($timezone)) {
        $errors[] = 'Timezone must be a valid PHP timezone identifier (e.g. UTC, Europe/London).';
    }
    if ($dateFormat === '') {
        $errors[] = 'Date format is required.';
    }
    if ($emailProvider === 'smtp') {
        if ($smtpHost === '') {
            $errors[] = 'SMTP host is required.';
        }
        if ($smtpPort === '' || !ctype_digit($smtpPort)) {
            $errors[] = 'SMTP port must be a number.';
        }
        if (!in_array($smtpEnc, ['tls', 'ssl', ''], true)) {
            $errors[] = 'SMTP encryption must be tls, ssl, or none.';
        }
    }

    $sodiumHex = bin2hex(random_bytes(32));

    if (empty($errors)) {
        $configPhp = build_config_php([
            'admin_username' => $adminUsername,
            'admin_password_hash' => password_hash($adminPassword, PASSWORD_DEFAULT),
            'sodium_key_hex' => $sodiumHex,
            'timezone' => normalize_comments_timezone($timezone),
            'date_format' => normalize_comments_date_format($dateFormat),
            'privacy_policy_url' => '/privacy#commenting',
            'post_titles' => [],
            'spam_challenge_question' => $spamChallengeQuestion,
            'spam_challenge_answer' => $spamChallengeAnswer,
            'spam_challenge_placeholder' => $spamChallengePlaceholder,
            'post_base_url' => rtrim($postBaseUrl, '/'),
            'author_name' => $authorName,
            'author_email' => $authorEmail,
            'aws_region' => $awsRegion,
            'aws_access_key' => $awsAccessKey,
            'aws_secret_key' => $awsSecretKey,
            'source_email' => $sourceEmail,
            'source_name' => $sourceName,
            'smtp_host' => $smtpHost,
            'smtp_port' => $smtpPort,
            'smtp_user' => $smtpUser,
            'smtp_pwd' => $smtpPwd,
            'smtp_enc' => $smtpEnc,
            'notify_email' => $notifyEmail,
            'moderation_base_url' => rtrim($moderationBaseUrl, '/') . '/',
        ]);

        if (@file_put_contents($configPath, $configPhp, LOCK_EX) === false) {
            $errors[] = 'Unable to write config.php. Check filesystem permissions.';
        } else {
            /** @var array $config */
            $config = require $configPath;
            require __DIR__ . '/includes/db.php';
            db($config);
            $saved = true;
        }
    }
}

if ($saved) {
    $deleted = delete_setup_file();
    $query = ['setup' => 'complete'];
    if (!$deleted) {
        $query['setup_cleanup'] = 'failed';
    }
    header('Location: ' . pc_url('/login.php') . '?' . http_build_query($query), true, 302);
    exit;
}
$styleVersion = filemtime(__DIR__ . '/public/style.css');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pure Comments Setup</title>
    <link rel="stylesheet" href="<?php echo h(pc_url('/public/style.css')); ?>?v=<?php echo h((string)$styleVersion); ?>">
</head>
<body class="admin">
    <main class="admin-container">
        <h1>Pure Comments Setup</h1>

        <?php if (!empty($errors)) : ?>
            <div class="notice error">
                <strong>Setup errors:</strong>
                <ul>
                    <?php foreach ($errors as $error) : ?>
                        <li><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post" class="admin-form">
            <h2>Admin</h2>
            <label for="admin_username">Admin username</label>
            <input id="admin_username" name="admin_username" required value="<?php echo h($_POST['admin_username'] ?? ''); ?>">

            <label for="admin_password">Admin password</label>
            <input id="admin_password" name="admin_password" type="password" minlength="10" autocomplete="new-password" required>

            <label for="admin_password_confirm">Confirm admin password</label>
            <input id="admin_password_confirm" name="admin_password_confirm" type="password" minlength="10" autocomplete="new-password" required>

            <h2>Site</h2>
            <label for="post_base_url">Post base URL</label>
            <input id="post_base_url" name="post_base_url" required placeholder="https://example.com/blog" value="<?php echo h($_POST['post_base_url'] ?? ''); ?>">

            <label for="moderation_base_url">Comments service URL</label>
            <input id="moderation_base_url" name="moderation_base_url" required placeholder="https://comments.example.com" value="<?php echo h($_POST['moderation_base_url'] ?? ''); ?>">

            <label for="timezone">
                Timezone
                <small>(<a href="https://www.php.net/manual/en/timezones.php" target="_blank" rel="noopener noreferrer">PHP timezone list</a>)</small>
            </label>
            <input id="timezone" name="timezone" required placeholder="UTC" value="<?php echo h($_POST['timezone'] ?? default_comments_timezone()); ?>">

            <label for="date_format">
                Date format
                <small>(<a href="https://www.php.net/manual/en/datetime.format.php" target="_blank" rel="noopener noreferrer">PHP date format docs</a>)</small>
            </label>
            <input id="date_format" name="date_format" required placeholder="Y-m-d H:i" value="<?php echo h($_POST['date_format'] ?? default_comments_date_format()); ?>">

            <h2>Spam protection</h2>
            <label for="spam_challenge_question">Challenge question</label>
            <input
                id="spam_challenge_question"
                name="spam_challenge_question"
                required
                placeholder="What is your site name?"
                value="<?php echo h($_POST['spam_challenge_question'] ?? ''); ?>"
            >

            <label for="spam_challenge_answer">Challenge answer</label>
            <input
                id="spam_challenge_answer"
                name="spam_challenge_answer"
                required
                placeholder="Expected answer (case-insensitive)"
                value="<?php echo h($_POST['spam_challenge_answer'] ?? ''); ?>"
            >

            <label for="spam_challenge_placeholder">Challenge placeholder (optional)</label>
            <input
                id="spam_challenge_placeholder"
                name="spam_challenge_placeholder"
                placeholder="Your answer..."
                value="<?php echo h($_POST['spam_challenge_placeholder'] ?? ''); ?>"
            >

            <h2>Author</h2>
            <label for="author_name">Author name</label>
            <input id="author_name" name="author_name" required value="<?php echo h($_POST['author_name'] ?? ''); ?>">

            <label for="author_email">Author email</label>
            <input id="author_email" name="author_email" type="email" required value="<?php echo h($_POST['author_email'] ?? ''); ?>">

            <h2>Email notifications (optional)</h2>
            <label for="email_provider">Email provider</label>
            <select id="email_provider" name="email_provider">
                <option value="">None</option>
                <option value="ses" <?php echo ($_POST['email_provider'] ?? '') === 'ses' ? 'selected' : ''; ?>>Amazon SES</option>
                <option value="smtp" <?php echo ($_POST['email_provider'] ?? '') === 'smtp' ? 'selected' : ''; ?>>SMTP</option>
            </select>

            <label for="notify_email">Moderation notify email</label>
            <input id="notify_email" name="notify_email" type="email" value="<?php echo h($_POST['notify_email'] ?? ''); ?>">

            <div id="ses-settings" class="admin-form-section" hidden>
                <label for="aws_region">AWS region</label>
                <input id="aws_region" name="aws_region" placeholder="eu-west-1" value="<?php echo h($_POST['aws_region'] ?? ''); ?>">

                <label for="aws_access_key">AWS access key</label>
                <input id="aws_access_key" name="aws_access_key" value="<?php echo h($_POST['aws_access_key'] ?? ''); ?>">

                <label for="aws_secret_key">AWS secret key</label>
                <input id="aws_secret_key" name="aws_secret_key" value="<?php echo h($_POST['aws_secret_key'] ?? ''); ?>">

                <label for="source_email">Source email address</label>
                <input id="source_email" name="source_email" type="email" value="<?php echo h($_POST['source_email'] ?? ''); ?>">

                <label for="source_name">Source name</label>
                <input id="source_name" name="source_name" value="<?php echo h($_POST['source_name'] ?? ''); ?>">
            </div>

            <div id="smtp-settings" class="admin-form-section" hidden>
                <label for="smtp_host">SMTP host</label>
                <input id="smtp_host" name="smtp_host" placeholder="smtp.example.com" value="<?php echo h($_POST['smtp_host'] ?? ''); ?>">

                <label for="smtp_port">SMTP port</label>
                <input id="smtp_port" name="smtp_port" type="number" min="1" max="65535" placeholder="587" value="<?php echo h($_POST['smtp_port'] ?? '587'); ?>">

                <label for="smtp_enc">Encryption</label>
                <select id="smtp_enc" name="smtp_enc">
                    <option value="tls" <?php echo ($_POST['smtp_enc'] ?? 'tls') === 'tls' ? 'selected' : ''; ?>>STARTTLS (port 587)</option>
                    <option value="ssl" <?php echo ($_POST['smtp_enc'] ?? '') === 'ssl' ? 'selected' : ''; ?>>SSL/TLS (port 465)</option>
                    <option value="" <?php echo ($_POST['smtp_enc'] ?? '') === '' ? 'selected' : ''; ?>>None (port 25)</option>
                </select>

                <label for="smtp_user">SMTP username</label>
                <input id="smtp_user" name="smtp_user" autocomplete="off" value="<?php echo h($_POST['smtp_user'] ?? ''); ?>">

                <label for="smtp_pwd">SMTP password</label>
                <input id="smtp_pwd" name="smtp_pwd" type="password" autocomplete="new-password" value="<?php echo h($_POST['smtp_pwd'] ?? ''); ?>">
            </div>

            <button type="submit">
                <svg class="button-icon" aria-hidden="true" focusable="false"><use href="<?php echo h(pc_url('/public/icons/sprite.svg')); ?>#icon-login"></use></svg>
                <span>Create config and database</span>
            </button>
        </form>
    </main>
<script>
(function () {
    var sel = document.getElementById('email_provider');
    function update() {
        document.getElementById('ses-settings').hidden = sel.value !== 'ses';
        document.getElementById('smtp-settings').hidden = sel.value !== 'smtp';
    }
    sel.addEventListener('change', update);
    update();
}());
</script>
</body>
</html>
<?php

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function delete_setup_file(): bool
{
    return @unlink(__FILE__);
}
