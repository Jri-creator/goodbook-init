<?php

class DiscordWebhook {
    private $webhookUrl;
    private $postCount = 0;
    private $startTime;

    public function __construct($url) {
        $this->webhookUrl = $url;
        $this->startTime = time();
    }

    private function canPost() {
        $currentTime = time();
        // Check if an hour has passed
        if (($currentTime - $this->startTime) >= 3600) {
            // Reset the post count and start time
            $this->postCount = 0;
            $this->startTime = $currentTime;
        }
        return $this->postCount < 10;
    }

    public function sendEmbed($embed) {
        if (!$this->canPost()) {
            echo "Rate limit exceeded. Please wait before sending more messages.";
            return;
        }

        $data = json_encode(['embeds' => [$embed]]);

        $options = [
            'http' => [
                'header'  => "Content-Type: application/json\r\n",
                'method'  => 'POST',
                'content' => $data,
            ],
        ];
        $context  = stream_context_create($options);
        $result = @file_get_contents($this->webhookUrl, false, $context);

        if ($result === FALSE) {
            // Handle error gracefully
            echo "Failed to send webhook. Please check the webhook URL and try again.";
        } else {
            $this->postCount++;
            echo "Webhook sent successfully!";
        }
    }
}

// Example usage:
//$webhook = new DiscordWebhook('YOUR_DISCORD_WEBHOOK_URL');
//$embed = [
//    'title' => 'Title here',
//    'description' => 'Description here',
//    'url' => 'URL here',
//    'color' => 5620992
//];
//$webhook->sendEmbed($embed);
?>