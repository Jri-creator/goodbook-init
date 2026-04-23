<?php
// includes/post_card.php
// Expects $post array with fields from the posts query.
// Expects $me (current_user()) to be available.
$_pc_me = current_user();
$_pc_is_mine = $_pc_me && ((int)$post['author_id'] === (int)$_pc_me['id'] || (int)$post['wall_owner_id'] === (int)$_pc_me['id']);
$_pc_on_others_wall = (int)$post['author_id'] !== (int)$post['wall_owner_id'];
?>
<div class="post-card" id="post-<?= (int)$post['id'] ?>">
  <div class="post-header">
    <a href="profile.php?u=<?= h($post['username']) ?>">
      <img src="<?= avatar_url($post['author_avatar'], 40) ?>" class="post-avatar" alt="">
    </a>
    <div class="post-meta">
      <div class="post-author-name">
        <a href="profile.php?u=<?= h($post['username']) ?>" style="color:#3b5998;text-decoration:none;"><?= h($post['display_name']) ?></a>
        <?php if ($_pc_on_others_wall): ?>
          <span style="color:#888;font-weight:400;font-size:12px;"> → </span>
          <a href="profile.php?u=<?= h($post['wall_username']) ?>" style="color:#3b5998;text-decoration:none;font-size:12px;"><?= h($post['wall_display_name']) ?></a>
        <?php endif; ?>
      </div>
      <div class="post-date" data-ts="<?= h($post['created_at']) ?>" title="<?= h($post['created_at']) ?>"><?= time_ago($post['created_at']) ?></div>
    </div>
    <?php if ($_pc_is_mine): ?>
      <button class="post-action delete" onclick="deletePost(<?= (int)$post['id'] ?>)" title="Delete post" style="flex:0;padding:4px 8px;">✕</button>
    <?php endif; ?>
  </div>

  <div class="post-body">
    <?= format_post_content($post['content']) ?>
    <?php if ($post['image']): ?>
      <img src="<?= h(UPLOAD_URL . $post['image']) ?>" alt="Post image" style="max-width:100%;border-radius:6px;margin-top:8px;display:block;">
    <?php endif; ?>
  </div>

  <div class="like-count" id="like-label-<?= (int)$post['id'] ?>">
    <?php if ($post['like_count'] > 0): ?>
      👍 <?= (int)$post['like_count'] ?> <?= $post['like_count'] == 1 ? 'Like' : 'Likes' ?>
    <?php endif; ?>
  </div>

  <div class="post-footer">
    <?php if ($_pc_me): ?>
    <button class="post-action <?= $post['i_liked'] ? 'liked' : '' ?>" id="like-btn-<?= (int)$post['id'] ?>" onclick="toggleLike(<?= (int)$post['id'] ?>)">
      <?= $post['i_liked'] ? '👍 Liked' : '👍 Like' ?>
    </button>
    <?php endif; ?>
    <button class="post-action" onclick="toggleComments(<?= (int)$post['id'] ?>)">
      💬 Comment<?= $post['comment_count'] > 0 ? ' (' . (int)$post['comment_count'] . ')' : '' ?>
    </button>
  </div>

  <div class="comments-section" id="comments-<?= (int)$post['id'] ?>">
    <div class="comments-list" id="clist-<?= (int)$post['id'] ?>">
      <!-- Loaded on toggle -->
    </div>
    <?php if ($_pc_me): ?>
    <div class="comment-form">
      <div style="display:flex;gap:8px;align-items:center;">
        <img src="<?= avatar_url($_pc_me['avatar'] ?? null, 28) ?>" style="width:28px;height:28px;border-radius:50%;object-fit:cover;flex-shrink:0;">
        <input type="text" class="comment-input" id="cinput-<?= (int)$post['id'] ?>" placeholder="Write a comment..." onkeydown="if(event.key==='Enter'){submitComment(<?= (int)$post['id'] ?>);}">
        <button onclick="submitComment(<?= (int)$post['id'] ?>)" style="background:#3b5998;color:white;border:none;border-radius:12px;padding:5px 12px;font-size:12px;font-family:Tahoma,Arial,sans-serif;cursor:pointer;white-space:nowrap;">Post</button>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>
