<?php
// includes/discord.php — Discord webhook utilities

/**
 * Send a post to Discord webhook
 * Returns true on success, false on failure (silently)
 */
function send_discord_webhook(array $user, array $post, array $author): bool {
    if (!$user['discord_webhook_url'] || !$user['discord_webhook_enabled']) {
        return false;
    }
    
    // Check rate limit (10 posts per hour)
    if (!check_discord_rate_limit($user['id'])) {
        return false;
    }
    
    // Build embed
    $embed = build_discord_embed($user, $post, $author);
    
    // Send to Discord
    $payload = json_encode(['embeds' => [$embed]]);
    
    $ch = curl_init($user['discord_webhook_url']);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    // Silently fail if Discord is down or URL is invalid
    if ($http_code < 200 || $http_code >= 300) {
        return false;
    }
    
    // Increment rate limit counter
    increment_discord_counter($user['id']);
    return true;
}

/**
 * Build Discord embed from post data
 */
function build_discord_embed(array $user, array $post, array $author): array {
    $content = $post['content'];
    
    // Decode mojis from [moji:ID] back to :name:
    $content = decode_mojis_for_discord($content);
    
    // Render Markdown but keep it plain for Discord (strip HTML)
    $content = strip_tags(format_post_content($content));
    
    $embed = [
        'author' => [
            'name' => $user['display_name'] . '\'s Goodbook',
        ],
        'title' => $author['display_name'],
        'description' => mb_substr($content, 0, 2000),
        'color' => 3447003, // Blurple
        'footer' => [
            'text' => 'Posted ' . time_ago($post['created_at']),
        ],
    ];
    
    // Add image if present
    if ($post['image']) {
        $embed['image'] = [
            'url' => UPLOAD_URL . $post['image'],
        ];
    }
    
    // Add link back to profile
    $embed['url'] = 'profile.php?u=' . urlencode($user['username']);
    
    return $embed;
}

/**
 * Decode [moji:ID] back to :name: for Discord display
 */
function decode_mojis_for_discord(string $text): string {
    return preg_replace_callback('/\[moji:(\d+)\]/', function($m) {
        $id = (int)$m[1];
        $stmt = db()->prepare("SELECT name FROM mojis WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? ':' . $row['name'] . ':' : '';
    }, $text);
}

/**
 * Check if user has reached rate limit (10 per hour)
 */
function check_discord_rate_limit(int $user_id): bool {
    $stmt = db()->prepare("SELECT discord_posts_today, discord_last_reset FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$user_id]);
    $row = $stmt->fetch();
    
    if (!$row) return false;
    
    $last_reset = $row['discord_last_reset'] ? strtotime($row['discord_last_reset']) : 0;
    $now = time();
    
    // Reset counter if 1 hour has passed
    if ($now - $last_reset > 3600) {
        db()->prepare("UPDATE users SET discord_posts_today = 0, discord_last_reset = NOW() WHERE id = ?")
            ->execute([$user_id]);
        return true;
    }
    
    // Check if under limit
    return (int)$row['discord_posts_today'] < 10;
}

/**
 * Increment the rate limit counter
 */
function increment_discord_counter(int $user_id): void {
    db()->prepare("UPDATE users SET discord_posts_today = discord_posts_today + 1 WHERE id = ?")
        ->execute([$user_id]);
}

/**
 * Validate Discord webhook URL format
 */
function validate_discord_webhook_url(string $url): bool {
    return (bool)filter_var($url, FILTER_VALIDATE_URL) && 
           strpos($url, 'discord.com/api/webhooks/') !== false;
}
