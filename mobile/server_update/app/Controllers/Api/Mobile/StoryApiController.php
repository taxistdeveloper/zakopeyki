<?php

declare(strict_types=1);

namespace App\Controllers\Api\Mobile;

use App\Helpers\AvatarHelper;
use App\Helpers\ProductHelper;
use App\Helpers\UploadHelper;
use App\Models\Chat;
use App\Models\Follow;
use App\Models\Product;
use App\Models\Story;
use App\Models\User;
use App\Services\MobileApi\Presenter;

/**
 * Истории (24 ч) в приложении: лента, публикация фото/видео, удаление, ответ автору в чат.
 * Те же правила видимости и загрузки, что у сайта (Story::activeGrouped, StoryController::store).
 */
final class StoryApiController extends BaseMobileController
{
    private const IMAGE_EXT = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    private const VIDEO_EXT = ['mp4', 'webm', 'mov'];
    private const MAX_IMAGE = 5 * 1024 * 1024;
    private const MAX_VIDEO = 50 * 1024 * 1024;

    /** GET /stories — группы по авторам (свои — первыми) */
    public function index(): void
    {
        $this->guardSiteOpen();
        $uid = $this->optionalUserId();
        $groups = array_map(fn (array $g): array => $this->presentGroup($g, $uid), (new Story())->activeGrouped($uid));
        usort($groups, static fn (array $a, array $b): int => (int) $b['is_mine'] <=> (int) $a['is_mine']);
        $this->ok(['groups' => $groups]);
    }

    /**
     * POST /stories (multipart: media, caption?, audience: all|followers, visibility: public|private,
     * comments_enabled: 0|1, product_mode: auto|pick|none, product_id?, notify_subs: 0|1)
     */
    public function create(): void
    {
        $uid = $this->requireUserId();
        $this->guardSiteOpen();
        $body = $this->body();
        $caption = mb_substr(trim((string) ($body['caption'] ?? '')), 0, 280);

        $productMode = in_array($body['product_mode'] ?? 'auto', ['auto', 'pick', 'none'], true) ? (string) $body['product_mode'] : 'auto';
        $pickedId = null;
        if ($productMode === 'pick') {
            $pickedId = (int) ($body['product_id'] ?? 0);
            $picked = $pickedId > 0 ? (new Product())->find($pickedId) : null;
            if (!$picked || (int) $picked['user_id'] !== $uid || ($picked['status'] ?? '') !== 'active') {
                $this->fail('validation_failed', 422, t('home.story_create_product_pick_need'), ['product_id' => t('home.story_create_product_pick_need')]);
            }
        }

        $file = $this->storeMedia($uid);
        $flag = static fn (string $k, bool $def): bool => !isset($body[$k]) ? $def : in_array((string) $body[$k], ['1', 'true', 'on', 'yes'], true);
        $visibility = ($body['visibility'] ?? 'public') === 'private' ? 'private' : 'public';

        $id = (new Story())->create([
            'user_id' => $uid,
            'caption' => $caption !== '' ? $caption : null,
            'image' => $file,
            'bg_color' => '#7c3aed',
            'emoji' => '✨',
            'audience' => ($body['audience'] ?? 'all') === 'followers' ? 'followers' : 'all',
            'comments_enabled' => $flag('comments_enabled', true) ? 1 : 0,
            'visibility' => $visibility,
            'attach_product' => $productMode !== 'none' ? 1 : 0,
            'product_id' => $productMode === 'pick' ? $pickedId : null,
        ]);

        if ($visibility !== 'private' && $flag('notify_subs', true)) {
            $name = (string) ((new User())->find($uid)['name'] ?? 'Продавец');
            (new Follow())->notifyFollowers($uid, t('seller.notify_story', ['name' => $name]));
        }
        $this->ok(['story_id' => $id], [], 201);
    }

    /** POST /stories/{id}/delete */
    public function delete(string $id): void
    {
        $uid = $this->requireUserId();
        $model = new Story();
        $story = $model->find((int) $id);
        if (!$story || (int) $story['user_id'] !== $uid) {
            $this->fail('not_found', 404);
        }
        if (!empty($story['image'])) {
            $path = dirname(__DIR__, 4) . '/public/uploads/stories/' . basename((string) $story['image']);
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $model->delete((int) $id);
        $this->ok(['deleted' => true]);
    }

    /** POST /stories/{id}/reply {body} — ответ автору: сообщение в личный чат со ссылкой на историю */
    public function reply(string $id): void
    {
        $uid = $this->requireUserId();
        $this->guardSiteOpen();
        $story = (new Story())->find((int) $id);
        if (!$story) {
            $this->fail('not_found', 404);
        }
        if (array_key_exists('comments_enabled', $story) && (int) $story['comments_enabled'] === 0) {
            $this->fail('comments_disabled', 403, 'Автор отключил ответы на эту историю');
        }
        $chat = new Chat();
        $start = $chat->start($uid, (int) $story['user_id']);
        if (empty($start['ok'])) {
            $this->fail('chat_start_failed', 422, (string) ($start['error'] ?? t('chat.start_failed')));
        }
        $res = $chat->send((int) $start['conversation_id'], $uid, (string) ($this->body()['body'] ?? ''), (int) $id);
        if (empty($res['ok'])) {
            $this->fail('chat_send_failed', 422, (string) ($res['error'] ?? t('chat.send_failed')));
        }
        $this->ok(['conversation_id' => (int) $start['conversation_id']], [], 201);
    }

    // ---------------------------------------------------------------- helpers

    private function storeMedia(int $uid): string
    {
        $file = $_FILES['media'] ?? $_FILES['image'] ?? null;
        if (!is_array($file) || empty($file['name']) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            $this->fail('media_required', 422, t('home.story_create_need_photo'));
        }
        $err = (int) ($file['error'] ?? UPLOAD_ERR_OK);
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            $this->fail('media_too_big', 422, t('home.story_create_file_big'));
        }
        if ($err !== UPLOAD_ERR_OK) {
            $this->fail('media_bad', 422, t('home.story_create_media_bad'));
        }
        $original = (string) $file['name'];
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        $isVideo = in_array($ext, self::VIDEO_EXT, true);
        if (!$isVideo && !in_array($ext, self::IMAGE_EXT, true)) {
            $this->fail('media_bad', 422, t('home.story_create_media_bad'));
        }
        if ((int) ($file['size'] ?? 0) > ($isVideo ? self::MAX_VIDEO : self::MAX_IMAGE)) {
            $this->fail('media_too_big', 422, t('home.story_create_file_big'));
        }
        if (!UploadHelper::isAllowedUpload((string) $file['tmp_name'], $original, $isVideo ? self::VIDEO_EXT : self::IMAGE_EXT)) {
            $this->fail('media_bad', 422, t('home.story_create_media_bad'));
        }
        $dir = dirname(__DIR__, 4) . '/public/uploads/stories';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $name = 'story_' . $uid . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . UploadHelper::normalizeExt($original);
        if (!move_uploaded_file((string) $file['tmp_name'], $dir . '/' . $name)) {
            $this->fail('media_bad', 500, t('home.story_create_media_bad'));
        }

        return $name;
    }

    /** @param array<string, mixed> $g строка Story::activeGrouped */
    private function presentGroup(array $g, ?int $uid): array
    {
        $authorId = (int) $g['user_id'];

        return [
            'user' => [
                'id' => $authorId,
                'name' => (string) ($g['user_name'] ?? ''),
                'avatar_url' => Presenter::media(AvatarHelper::url([
                    'avatar' => $g['user_avatar'] ?? null,
                    'avatar_file' => $g['user_avatar_file'] ?? null,
                ])),
            ],
            'is_mine' => $uid !== null && $uid === $authorId,
            'is_following' => (bool) ($g['is_following'] ?? false),
            'stories' => array_map(function (array $s): array {
                $media = (string) ($s['image'] ?? '');
                $ext = strtolower(pathinfo($media, PATHINFO_EXTENSION));

                return [
                    'id' => (int) $s['id'],
                    'media_url' => $media !== '' ? Presenter::media(ProductHelper::url('public/uploads/stories/' . basename($media))) : null,
                    'is_video' => in_array($ext, self::VIDEO_EXT, true),
                    'caption' => ($s['caption'] ?? '') !== '' ? (string) $s['caption'] : null,
                    'bg_color' => (string) ($s['bg_color'] ?? '#7c3aed'),
                    'comments_enabled' => !array_key_exists('comments_enabled', $s) || (int) $s['comments_enabled'] === 1,
                    'created_at' => Presenter::date($s['created_at'] ?? null),
                    'expires_at' => Presenter::date($s['expires_at'] ?? null),
                    'product' => $this->productSticker($s['linked_product'] ?? null),
                ];
            }, $g['stories'] ?? []),
        ];
    }

    private function productSticker(mixed $p): ?array
    {
        if (!is_array($p) || empty($p['id'])) {
            return null;
        }

        return [
            'id' => (int) $p['id'],
            'title' => (string) ($p['title'] ?? ''),
            'price_text' => ProductHelper::formatPrice($p),
            'image_url' => Presenter::media(ProductHelper::imageUrl($p)),
        ];
    }
}
