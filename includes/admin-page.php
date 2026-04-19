<?php
/**
 * Admin Page Template
 *
 * Variables available: $players, $recent_backups (passed from SP_Merge_Admin::render_admin_page).
 *
 * @package SportsPress_Player_Merge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<div class="wrap sp-merge-wrap">
	<!-- Page Header -->
	<div class="sp-merge-header">
		<h1 class="sp-merge-title">
			<span class="dashicons dashicons-admin-users"></span>
			<?php esc_html_e( 'SportsPress Player Merge Tool', 'sportspress-player-merge' ); ?>
		</h1>
		<p class="sp-merge-description">
			<?php esc_html_e( 'Merge duplicate players while preserving all data. Changes can be reverted if needed.', 'sportspress-player-merge' ); ?>
		</p>
	</div>

	<!-- Main Content Container -->
	<div class="sp-merge-container">

		<!-- Merge Form Card -->
		<div class="sp-merge-card">
			<div class="sp-merge-card-header">
				<h2><?php esc_html_e( 'Select Players to Merge', 'sportspress-player-merge' ); ?></h2>
			</div>

			<div class="sp-merge-card-body">
				<form id="sp-merge-form">
					<?php wp_nonce_field( 'sp_merge_nonce', 'sp_merge_nonce' ); ?>

					<!-- Primary Player Selection -->
					<div class="sp-form-group">
						<label for="primary-player" class="sp-form-label">
							<span class="dashicons dashicons-star-filled"></span>
							<?php esc_html_e( 'Primary Player (will be kept)', 'sportspress-player-merge' ); ?>
						</label>
						<select name="primary_player" id="primary-player" class="sp-player-search" data-placeholder="<?php esc_attr_e( 'Search for the player to keep...', 'sportspress-player-merge' ); ?>" required>
							<option value=""></option>
						</select>
						<p class="sp-form-help"><?php esc_html_e( 'This player will retain all data and remain in the system.', 'sportspress-player-merge' ); ?></p>
					</div>

					<!-- Duplicate Players Selection -->
					<div class="sp-form-group">
						<label for="duplicate-players" class="sp-form-label">
							<span class="dashicons dashicons-trash"></span>
							<?php esc_html_e( 'Duplicate Players (will be merged and deleted)', 'sportspress-player-merge' ); ?>
						</label>
						<select name="duplicate_players[]" id="duplicate-players" class="sp-player-search" data-placeholder="<?php esc_attr_e( 'Search for duplicate players...', 'sportspress-player-merge' ); ?>" multiple>
						</select>
						<p class="sp-form-help">
							<span class="dashicons dashicons-info"></span>
							<?php esc_html_e( 'Type to search. Select up to 10 duplicate players.', 'sportspress-player-merge' ); ?>
						</p>
					</div>

					<!-- Action Buttons -->
					<div class="sp-form-actions">
						<button type="button" id="preview-merge" class="button button-secondary sp-btn-preview">
							<span class="dashicons dashicons-visibility"></span>
							<?php esc_html_e( 'Preview Merge', 'sportspress-player-merge' ); ?>
						</button>
						<button type="button" id="execute-merge" class="button button-primary sp-btn-execute" disabled>
							<span class="dashicons dashicons-admin-tools"></span>
							<?php esc_html_e( 'Execute Merge', 'sportspress-player-merge' ); ?>
						</button>
						<button type="button" id="revert-merge" class="button button-secondary sp-btn-revert<?php echo empty( $recent_backups ) ? ' sp-hidden' : ''; ?>" <?php disabled( empty( $recent_backups ) ); ?>>
							<span class="dashicons dashicons-undo"></span>
							<?php esc_html_e( 'Revert Last Merge', 'sportspress-player-merge' ); ?>
						</button>
					</div>
				</form>
			</div>
		</div>

		<!-- Preview Results Card -->
		<div id="merge-preview-card" class="sp-merge-card sp-hidden">
			<div class="sp-merge-card-header">
				<h2>
					<span class="dashicons dashicons-visibility"></span>
					<?php esc_html_e( 'Merge Preview', 'sportspress-player-merge' ); ?>
				</h2>
				<button type="button" id="cancel-preview" class="button button-secondary">
					<span class="dashicons dashicons-no"></span>
					<?php esc_html_e( 'Cancel Preview', 'sportspress-player-merge' ); ?>
				</button>
			</div>
			<div class="sp-merge-card-body">
				<div id="preview-content"></div>
			</div>
		</div>

		<!-- Recent Backups -->
		<?php if ( ! empty( $recent_backups ) ) : ?>
		<div class="sp-merge-card sp-backup-section">
			<div class="sp-merge-card-header">
				<h2>
					<span class="dashicons dashicons-backup"></span>
					<?php esc_html_e( 'Recent Merges (Available for Revert)', 'sportspress-player-merge' ); ?>
				</h2>
				<div class="sp-backup-actions">
					<button type="button" id="select-all-backups" class="button button-secondary"><?php esc_html_e( 'Select All', 'sportspress-player-merge' ); ?></button>
					<button type="button" id="delete-selected-backups" class="button button-secondary" disabled>
						<span class="dashicons dashicons-trash"></span> <?php esc_html_e( 'Delete Selected', 'sportspress-player-merge' ); ?>
					</button>
				</div>
			</div>
			<div class="sp-merge-card-body">
				<?php foreach ( $recent_backups as $backup ) : ?>
				<div class="sp-backup-item">
					<input type="checkbox" class="backup-checkbox" value="<?php echo esc_attr( $backup['id'] ); ?>" id="backup-<?php echo esc_attr( $backup['id'] ); ?>">
					<label for="backup-<?php echo esc_attr( $backup['id'] ); ?>">
						<strong><?php echo esc_html( $backup['primary_name'] ); ?></strong> &larr; <?php echo esc_html( implode( ', ', $backup['duplicate_names'] ) ); ?>
					</label>
					<span class="sp-backup-date"><?php echo esc_html( $backup['date'] ); ?></span>
					<div class="sp-backup-buttons">
						<button type="button" class="button button-secondary sp-revert-backup" data-backup-id="<?php echo esc_attr( $backup['id'] ); ?>">
							<span class="dashicons dashicons-undo"></span> <?php esc_html_e( 'Revert', 'sportspress-player-merge' ); ?>
						</button>
						<button type="button" class="button button-secondary sp-delete-backup" data-backup-id="<?php echo esc_attr( $backup['id'] ); ?>">
							<span class="dashicons dashicons-trash"></span> <?php esc_html_e( 'Delete', 'sportspress-player-merge' ); ?>
						</button>
					</div>
				</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php endif; ?>

		<!-- Possible Duplicates -->
		<div class="sp-merge-card sp-duplicates-section">
			<div class="sp-merge-card-header">
				<h2>
					<span class="dashicons dashicons-search"></span>
					<?php esc_html_e( 'Possible Duplicates', 'sportspress-player-merge' ); ?>
				</h2>
				<button type="button" id="scan-duplicates" class="button button-secondary">
					<?php esc_html_e( 'Scan for Duplicates', 'sportspress-player-merge' ); ?>
				</button>
			</div>
			<div class="sp-merge-card-body">
				<div id="duplicates-content">
					<p><?php esc_html_e( "Click 'Scan for Duplicates' to find players with matching names.", 'sportspress-player-merge' ); ?></p>
				</div>
			</div>
		</div>

		<!-- Status Messages -->
		<div id="sp-merge-messages" class="sp-merge-messages" aria-live="polite"></div>

		<!-- Help Section -->
		<div class="sp-merge-card">
			<div class="sp-merge-card-header">
				<h2>
					<span class="dashicons dashicons-info"></span>
					<?php esc_html_e( 'How to Use This Tool', 'sportspress-player-merge' ); ?>
				</h2>
			</div>
			<div class="sp-merge-card-body">
				<ol>
					<li><strong><?php esc_html_e( 'Select Primary Player:', 'sportspress-player-merge' ); ?></strong> <?php esc_html_e( 'Choose the player you want to keep. This player will retain all existing data.', 'sportspress-player-merge' ); ?></li>
					<li><strong><?php esc_html_e( 'Select Duplicates:', 'sportspress-player-merge' ); ?></strong> <?php esc_html_e( 'Choose one or more duplicate players to merge into the primary player.', 'sportspress-player-merge' ); ?></li>
					<li><strong><?php esc_html_e( 'Preview:', 'sportspress-player-merge' ); ?></strong> <?php esc_html_e( 'Click "Preview Merge" to see what data will be combined.', 'sportspress-player-merge' ); ?></li>
					<li><strong><?php esc_html_e( 'Execute:', 'sportspress-player-merge' ); ?></strong> <?php esc_html_e( 'Click "Execute Merge" to perform the merge operation.', 'sportspress-player-merge' ); ?></li>
					<li><strong><?php esc_html_e( 'Revert:', 'sportspress-player-merge' ); ?></strong> <?php esc_html_e( 'If needed, you can revert the last merge operation to restore deleted players.', 'sportspress-player-merge' ); ?></li>
				</ol>

				<div class="sp-merge-warning">
					<p><strong><?php esc_html_e( 'Important:', 'sportspress-player-merge' ); ?></strong> <?php esc_html_e( 'Always preview your merge before executing. While merges can be reverted, it is always recommended to take a full database backup before performing any data manipulation operations.', 'sportspress-player-merge' ); ?></p>
				</div>

				<div class="sp-merge-disclaimer">
					<p><em><?php esc_html_e( 'SportsPress Player Merge (SP Merge) is not affiliated with or endorsed by the creators of SportsPress.', 'sportspress-player-merge' ); ?></em></p>
				</div>
			</div>
		</div>

	</div>

	<!-- Loading Overlay -->
	<div id="sp-merge-loading" class="sp-merge-loading sp-hidden" role="status" aria-live="polite">
		<div class="sp-loading-spinner">
			<div class="sp-spinner"></div>
			<p><?php esc_html_e( 'Processing merge operation...', 'sportspress-player-merge' ); ?></p>
		</div>
	</div>

</div>
