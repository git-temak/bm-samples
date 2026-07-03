<?php

$openai_api_key = get_option('openai_api_key');
$gemini_api_key = get_option('gemini_api_key');

define('OPENAI_KEY', $openai_api_key);
define('GEMINI_KEY', $gemini_api_key);
define('GEMINI_MODEL', 'veo-3.1-generate-preview');

add_action('rest_api_init', function () {
    register_rest_route('secure', '/video', array(
        'methods' => 'POST',
        'callback' => 'start_video_gen',
        'permission_callback' => '__return_true'
    ));
});

require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';

function start_video_gen(WP_REST_Request $request) {
    $prompt = sanitize_textarea_field($request->get_param('prompt'));
    $model = sanitize_text_field($request->get_param('model'));
    $video_url = $model == 'gemini' ? gemini_veo_start_generation($prompt) : openai_start_vid_generation($prompt);

    if (is_wp_error($video_url)) {
        return [
            'success'     => false,
            'message'     => $video_url->get_error_message(),
        ];
    }

    return [
        'success'     => true,
        'url'     => $video_url,
    ];
}

function openai_start_vid_generation($prompt) {
    $body = json_encode(
        [
            'model' => "sora-2-pro",
            'prompt' => $prompt,
            'seconds' => '12',
            'size' => '1024x1792',
        ]
    );

    $headers = [
        'Authorization' => 'Bearer ' . OPENAI_KEY,
        'Content-Type' => 'application/json',
    ];

    $url = 'https://api.openai.com/v1/videos';

    $response = wp_remote_post($url, [
        'headers' => $headers,
        'body' => $body,
        'timeout' => 120,
    ]);

    if (is_wp_error($response)) {
        return $response;
    }

    $data = json_decode(wp_remote_retrieve_body($response), true);
    if (!empty($data['error'])) {
        return new WP_Error('vid_error', $data['error']['message']);
    }

    $video_id = $data['id'];

    if (empty($video_id)) {
        return new WP_Error('vid_gen_error', 'Unable to generate video, please try again later');
    }

    $vid_ids = get_user_meta(1, 'oai_video_gen_ids', true) ?: [];
    $vid_ids[] = [
        'prompt' => $prompt,
        'video_id' => $video_id
    ];
    update_user_meta(1, 'oai_video_gen_ids', $vid_ids);

    $result = wait_for_sora_video($video_id);

    if (is_wp_error($result)) {
        return $response;
    }

    $video_url = sora_download_video($video_id);
    if (is_wp_error($video_url)) {
        return $response;
    }

    return $video_url;
}

function wait_for_sora_video($video_id, $max_wait = 300){
    $start = time();

    while (true) {
        $status_response = wp_remote_get(
            "https://api.openai.com/v1/videos/$video_id",
            [
                "headers" => [
                    'Authorization' => 'Bearer ' . OPENAI_KEY,
                ],
            ]
        );

        if (is_wp_error($status_response)) {
            return $status_response;
        }

        $data = json_decode(wp_remote_retrieve_body($status_response), true);

        if ($data['status'] === 'completed') {
            return $data;
        }

        if ($data['status'] === 'failed') {
            return new WP_Error('sora_failed', 'Video generation failed');
        }

        if ((time() - $start) > $max_wait) {
            return new WP_Error('timeout', 'Video generation timed out');
        }

        sleep(5); // wait before polling again
    }
}

function sora_download_video($video_id){
    $video_response = wp_remote_get(
        "https://api.openai.com/v1/videos/$video_id/content",
        [
            'headers' => [
                'Authorization' => 'Bearer ' . OPENAI_KEY,
            ],
            'timeout' => 300,
            // 'stream'   => true,
        ]
    );

    if (is_wp_error($video_response)) {
        return new WP_Error('download_failed', $video_response->get_error_message());
    }

    $video_binary = wp_remote_retrieve_body($video_response);

    $upload_dir = wp_upload_dir();
    $filename   = 'video-' . time() . '.mp4';
    $file_path  = $upload_dir['path'] . '/' . $filename;

    file_put_contents($file_path, $video_binary);

    $attachment = [
        'post_mime_type' => 'video/mp4',
        'post_title'     => sanitize_file_name($filename),
        'post_status'    => 'inherit',
    ];

    $attach_id = wp_insert_attachment($attachment, $file_path);

    wp_update_attachment_metadata(
        $attach_id,
        wp_generate_attachment_metadata($attach_id, $file_path)
    );

    return wp_get_attachment_url($attach_id);
}