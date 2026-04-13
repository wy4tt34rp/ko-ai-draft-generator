<?php
/**
 * Plugin Name: KO – AI Draft Generator
 * Description: Role-based tool to generate draft content using the OpenAI Responses API. Adds a sidebar meta box to selected post types. Settings page is hidden from admin nav and accessible only via Plugins screen.
 * Version: 1.3.2.3
 * Author: KO
 */

if ( ! defined('ABSPATH') ) exit;

class KO_AI_Draft_Generator {
	const OPT_KEY       = 'ko_ai_draft_generator_settings';
	const PAGE_SLUG     = 'ko-ai-draft-generator';
	const NONCE_ACTION  = 'ko_ai_draft_generator';
	const DEFAULT_MODEL = 'gpt-4.1';

	public function __construct() {
		add_action('admin_menu', [$this, 'register_hidden_settings_page'], 999);
		add_action('admin_init', [$this, 'register_settings']);

		add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'add_plugin_action_links']);

		add_action('add_meta_boxes', [$this, 'add_metaboxes']);
		add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);

		add_action('wp_ajax_ko_ai_generate', [$this, 'ajax_generate']);
		add_action('wp_ajax_ko_ai_apply',    [$this, 'ajax_apply']);
	}

	private function ko_404_die() {
		status_header(404);
		nocache_headers();
		wp_die(
			'404 Not Found',
			'404 Not Found',
			[
				'response'   => 404,
				'back_link'  => false,
			]
		);
	}

	/* -----------------------
	 * Settings storage
	 * --------------------- */
	public function defaults() {
		return [
			'api_key'            => '',
			'model'              => self::DEFAULT_MODEL,
			'temperature'        => 0.7,
			'max_output_tokens'  => 1200,
			'timeout'            => 45,
			'allowed_roles'      => [],           // NONE selected by default (deny all until configured)
			'enabled_post_types' => ['articles'], // keeps your current use case alive
		];
	}

	public function get_settings() {
		$saved = get_option(self::OPT_KEY, []);
		$s = wp_parse_args(is_array($saved) ? $saved : [], $this->defaults());

		$s['allowed_roles'] = is_array($s['allowed_roles']) ? $s['allowed_roles'] : [];
		$s['allowed_roles'] = array_values(array_filter(array_map('sanitize_key', $s['allowed_roles'])));

		$s['enabled_post_types'] = is_array($s['enabled_post_types']) ? $s['enabled_post_types'] : [];
		$s['enabled_post_types'] = array_values(array_filter(array_map('sanitize_key', $s['enabled_post_types'])));

		return $s;
	}

	public function user_is_allowed() {
		if ( ! is_user_logged_in() ) return false;

		$s = $this->get_settings();
		if ( empty($s['allowed_roles']) ) return false; // deny all until roles selected

		$user = wp_get_current_user();
		if ( empty($user) || empty($user->roles) ) return false;

		foreach ( (array)$user->roles as $r ) {
			if ( in_array($r, (array)$s['allowed_roles'], true) ) return true;
		}
		return false;
	}

	public function post_type_is_enabled($post_type) {
		$s = $this->get_settings();
		return in_array(sanitize_key($post_type), (array)$s['enabled_post_types'], true);
	}

	/* -----------------------
	 * Hidden settings page (no left nav)
	 * --------------------- */
	public function register_hidden_settings_page() {
		add_submenu_page(
			null,
			'KO AI Draft Generator',
			'KO AI Draft Generator',
			'manage_options',
			self::PAGE_SLUG,
			[$this, 'render_settings_page']
		);
	}

	public function add_plugin_action_links($links) {
		if ( ! current_user_can('manage_options') ) return $links;

		$url = admin_url('admin.php?page=' . self::PAGE_SLUG);
		$links = array_merge([ '<a href="' . esc_url($url) . '">Settings</a>' ], $links);

		return $links;
	}

	public function register_settings() {
		register_setting('ko_ai_draft_generator_group', self::OPT_KEY, [
			'type'              => 'array',
			'sanitize_callback' => [$this, 'sanitize_settings'],
			'default'           => $this->defaults(),
		]);
	}

	public function sanitize_settings($input) {
		$input = is_array($input) ? $input : [];
		$out = $this->defaults();

		$out['api_key'] = isset($input['api_key']) ? trim((string)$input['api_key']) : '';
		$out['model']   = isset($input['model']) ? sanitize_text_field((string)$input['model']) : self::DEFAULT_MODEL;

		$temp = isset($input['temperature']) ? (float)$input['temperature'] : 0.7;
		$out['temperature'] = max(0.0, min(2.0, $temp));

		$mot = isset($input['max_output_tokens']) ? (int)$input['max_output_tokens'] : 1200;
		$out['max_output_tokens'] = max(200, min(8000, $mot));

		$timeout = isset($input['timeout']) ? (int)$input['timeout'] : 45;
		$out['timeout'] = max(10, min(120, $timeout));

		$roles = isset($input['allowed_roles']) ? (array)$input['allowed_roles'] : [];
		$roles = array_values(array_filter(array_map('sanitize_key', $roles)));
		$out['allowed_roles'] = $roles; // allow empty = deny all

		$pts = isset($input['enabled_post_types']) ? (array)$input['enabled_post_types'] : [];
		$pts = array_values(array_filter(array_map('sanitize_key', $pts)));
		$out['enabled_post_types'] = $pts;

		return $out;
	}

	private function get_selectable_post_types() {
		$post_types = get_post_types(
			[
				'public'  => true,
				'show_ui' => true,
			],
			'objects'
		);

		unset($post_types['attachment']);
		return $post_types;
	}

	public function render_settings_page() {
		if ( ! current_user_can('manage_options') ) {
			$this->ko_404_die();
		}

		global $wp_roles;
		if ( ! $wp_roles ) $wp_roles = wp_roles();

		$s = $this->get_settings();
		$all_roles  = $wp_roles->roles;
		$post_types = $this->get_selectable_post_types();

		?>
		<div class="wrap">
			<h1>KO AI Draft Generator</h1>
			<p>Settings are intentionally hidden from the admin menu. Access is via the Plugins screen “Settings” link.</p>

			<?php if ( empty($s['allowed_roles']) ) : ?>
				<p class="description" style="color:#b32d2e;">
					<strong>No roles selected:</strong> The AI box is currently disabled for all users.
				</p>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields('ko_ai_draft_generator_group'); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ko_ai_api_key">OpenAI API Key</label></th>
						<td>
							<input type="password" id="ko_ai_api_key"
							       name="<?php echo esc_attr(self::OPT_KEY); ?>[api_key]"
							       value="<?php echo esc_attr($s['api_key']); ?>"
							       class="regular-text"
							       autocomplete="off" />

							<p class="description" style="margin-top:6px;">
								Get or manage your API keys at
								<a href="https://platform.openai.com/api-keys" target="_blank" rel="noopener noreferrer">
									platform.openai.com/api-keys
								</a>.
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="ko_ai_model">Model</label></th>
						<td>
							<input type="text" id="ko_ai_model" name="<?php echo esc_attr(self::OPT_KEY); ?>[model]"
							       value="<?php echo esc_attr($s['model']); ?>" class="regular-text" />
							<p class="description">Recommended: gpt-4o-mini (if available on your account).</p>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="ko_ai_temp">Temperature</label></th>
						<td>
							<input type="number" step="0.1" min="0" max="2" id="ko_ai_temp"
							       name="<?php echo esc_attr(self::OPT_KEY); ?>[temperature]"
							       value="<?php echo esc_attr($s['temperature']); ?>" />
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="ko_ai_tokens">Max Output Tokens</label></th>
						<td>
							<input type="number" min="200" max="8000" id="ko_ai_tokens"
							       name="<?php echo esc_attr(self::OPT_KEY); ?>[max_output_tokens]"
							       value="<?php echo esc_attr($s['max_output_tokens']); ?>" />
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="ko_ai_timeout">Timeout (seconds)</label></th>
						<td>
							<input type="number" min="10" max="120" id="ko_ai_timeout"
							       name="<?php echo esc_attr(self::OPT_KEY); ?>[timeout]"
							       value="<?php echo esc_attr($s['timeout']); ?>" />
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="ko_ai_roles">Allowed Roles</label></th>
						<td>
							<select id="ko_ai_roles" name="<?php echo esc_attr(self::OPT_KEY); ?>[allowed_roles][]" multiple size="10" style="min-width:320px;">
								<?php foreach ( $all_roles as $role_key => $role_data ): ?>
									<option value="<?php echo esc_attr($role_key); ?>"
										<?php selected( in_array($role_key, (array)$s['allowed_roles'], true) ); ?>>
										<?php echo esc_html($role_data['name']); ?> (<?php echo esc_html($role_key); ?>)
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description">Only these roles will see and use the AI box.</p>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="ko_ai_post_types">Enabled Post Types</label></th>
						<td>
							<select id="ko_ai_post_types" name="<?php echo esc_attr(self::OPT_KEY); ?>[enabled_post_types][]" multiple size="10" style="min-width:320px;">
								<?php foreach ( $post_types as $pt_key => $pt_obj ): ?>
									<option value="<?php echo esc_attr($pt_key); ?>"
										<?php selected( in_array($pt_key, (array)$s['enabled_post_types'], true) ); ?>>
										<?php echo esc_html($pt_obj->labels->singular_name); ?> (<?php echo esc_html($pt_key); ?>)
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description">The AI box will appear on the edit screen for selected post types.</p>
						</td>
					</tr>
				</table>

				<?php submit_button('Save Settings'); ?>
			</form>
		</div>
		<?php
	}

	/* -----------------------
	 * Meta box (sidebar)
	 * --------------------- */
	public function add_metaboxes() {
		if ( ! $this->user_is_allowed() ) return;

		$s = $this->get_settings();
		if ( empty($s['enabled_post_types']) ) return;

		foreach ( (array)$s['enabled_post_types'] as $pt ) {
			add_meta_box(
				'ko_ai_draft_generator_box',
				'KO – AI Draft Generator',
				[$this, 'render_metabox'],
				$pt,
				'side',
				'high'
			);
		}
	}

	public function render_metabox($post) {
		if ( ! current_user_can('edit_post', $post->ID) ) {
			echo '<p>You do not have permission to use this tool.</p>';
			return;
		}

		?>
		<div class="ko-ai-sidebar">
			<?php wp_nonce_field(self::NONCE_ACTION, 'ko_ai_nonce_field'); ?>
			<input type="hidden" id="ko_ai_post_id" value="<?php echo (int)$post->ID; ?>" />

			<p style="margin-top:0;">
				<label><strong>Topic / Prompt</strong></label>
				<textarea id="ko_ai_topic" rows="3" style="width:100%;" placeholder="What should this be about?"></textarea>
			</p>

			<p>
				<label><strong>Tone</strong></label>
				<select id="ko_ai_tone" style="width:100%;">
					<option value="professional">Professional</option>
					<option value="friendly">Friendly</option>
					<option value="authoritative">Authoritative</option>
					<option value="conversational">Conversational</option>
					<option value="technical">Technical</option>
				</select>
			</p>

			<p>
				<label><strong>Length</strong></label>
				<select id="ko_ai_length" style="width:100%;">
					<option value="short">Short</option>
					<option value="medium" selected>Medium</option>
					<option value="long">Long</option>
				</select>
			</p>

			<p>
				<label><strong>Keywords</strong></label>
				<input type="text" id="ko_ai_keywords" style="width:100%;" placeholder="comma separated" />
			</p>

			<p>
				<label style="display:flex; gap:6px; align-items:center; margin:0;">
					<input type="checkbox" id="ko_ai_use_existing" />
					Use current draft as context
				</label>
			</p>

			<p class="ko-ai-actions" style="margin: 10px 0 0 0;">
				<button type="button" class="button button-primary" id="ko_ai_generate_btn">Generate</button>
			</p>

			<span id="ko_ai_status" style="display:block; margin-top:6px;"></span>

			<p style="margin-top:10px;">
				<label><strong>Preview</strong></label>
				<div id="ko_ai_preview" class="ko-ai-preview">
					<em>Generated content preview…</em>
				</div>
				<textarea id="ko_ai_output" style="display:none;" aria-hidden="true"></textarea>
			</p>

			<p class="ko-ai-actions" style="margin: 10px 0 0 0;">
				<button type="button" class="button button-primary" id="ko_ai_apply_replace">Add to Content</button>
			</p>

			<p class="ko-ai-note">
				<strong>NOTE:</strong> You can copy/paste the preview text into the content area if you prefer.
			</p>
		</div>
		<?php
	}

	public function enqueue_admin_assets() {
		$screen = function_exists('get_current_screen') ? get_current_screen() : null;
		if ( ! $screen || $screen->base !== 'post' ) return;

		if ( ! $this->user_is_allowed() ) return;
		if ( ! $this->post_type_is_enabled($screen->post_type) ) return;

		// Styles
		wp_register_style('ko-ai-draft-admin', false, [], '1.3.2.3');
		wp_enqueue_style('ko-ai-draft-admin');
		wp_add_inline_style('ko-ai-draft-admin', '
#ko_ai_draft_generator_box .ko-ai-actions{display:flex; gap:8px;}
#ko_ai_draft_generator_box .ko-ai-actions .button{width:auto !important; max-width:150px;}
#ko_ai_draft_generator_box .ko-ai-preview{width:100%; max-height:220px; overflow:auto; border:1px solid #ccd0d4; border-radius:4px; padding:8px; background:#fff; box-sizing:border-box; word-break:break-word; overflow-wrap:anywhere;}
#ko_ai_draft_generator_box .ko-ai-preview img, #ko_ai_draft_generator_box .ko-ai-preview iframe, #ko_ai_draft_generator_box .ko-ai-preview video{max-width:100% !important; height:auto !important;}
#ko_ai_draft_generator_box .ko-ai-preview pre, #ko_ai_draft_generator_box .ko-ai-preview code{white-space:pre-wrap; word-break:break-word;}
#ko_ai_draft_generator_box .ko-ai-note{margin:20px 0 0 0; font-size:12px; color:#666;}
');

		// Scripts
		wp_enqueue_script('jquery');

		$ajax_url = admin_url('admin-ajax.php');
		$nonce    = wp_create_nonce(self::NONCE_ACTION);

		wp_register_script('ko-ai-draft-admin', false, ['jquery'], '1.3.2.3', true);
		wp_enqueue_script('ko-ai-draft-admin');

		wp_add_inline_script('ko-ai-draft-admin', "
jQuery(function($){

	function setStatus(msg, isError){
		$('#ko_ai_status').text(msg).css('color', isError ? '#b32d2e' : '#2271b1');
	}

	function basePayload(){
		return {
			nonce: '{$nonce}',
			post_id: $('#ko_ai_post_id').val()
		};
	}

	$(document).on('click', '#ko_ai_generate_btn', function(){
		var topic = ($('#ko_ai_topic').val() || '').trim();
		if(!topic){
			setStatus('Enter a topic/prompt first.', true);
			return;
		}

		setStatus('Generating…', false);
		$('#ko_ai_preview').html('<em>Generating…</em>');
		$('#ko_ai_output').val('');

		var payload = $.extend(basePayload(), {
			action: 'ko_ai_generate',
			topic: topic,
			tone: $('#ko_ai_tone').val(),
			length: $('#ko_ai_length').val(),
			keywords: $('#ko_ai_keywords').val(),
			use_existing: $('#ko_ai_use_existing').is(':checked') ? 1 : 0
		});

		$.post('{$ajax_url}', payload).done(function(resp){
			if(resp && resp.success && resp.data && resp.data.text){
				$('#ko_ai_output').val(resp.data.text);
				$('#ko_ai_preview').html(resp.data.text);
				setStatus('Done.', false);
			} else {
				var msg = (resp && resp.data && resp.data.message) ? resp.data.message : 'Unexpected response.';
				$('#ko_ai_preview').html('<em>' + msg + '</em>');
				setStatus(msg, true);
			}
		}).fail(function(xhr){
			var msg = 'Request failed.';
			if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
				msg = xhr.responseJSON.data.message;
			}
			$('#ko_ai_preview').html('<em>' + msg + '</em>');
			setStatus(msg, true);
		});
	});

	function apply(){
		var html = $('#ko_ai_output').val();
		if(!html){
			setStatus('Nothing to add yet. Click Generate first.', true);
			return;
		}

		setStatus('Saving…', false);

		var payload = $.extend(basePayload(), {
			action: 'ko_ai_apply',
			mode: 'replace',
			html: html
		});

		$.post('{$ajax_url}', payload).done(function(resp){
			if(resp && resp.success){
				setStatus('Saved. Opening editor…', false);
				if (resp.data && resp.data.redirect) {
					window.location.href = resp.data.redirect;
				} else {
					window.location.reload();
				}
			} else {
				var msg = (resp && resp.data && resp.data.message) ? resp.data.message : 'Save failed.';
				setStatus(msg, true);
			}
		}).fail(function(xhr){
			var msg = 'Save request failed.';
			if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
				msg = xhr.responseJSON.data.message;
			}
			setStatus(msg, true);
		});
	}

	$(document).on('click', '#ko_ai_apply_replace', function(){ apply(); });

});
");
	}

	/* -----------------------
	 * AJAX: generate
	 * --------------------- */
	public function ajax_generate() {
		if ( ! $this->user_is_allowed() ) {
			wp_send_json_error(['message' => 'Not allowed.'], 403);
		}

		$nonce = isset($_POST['nonce']) ? sanitize_text_field((string)$_POST['nonce']) : '';
		if ( ! wp_verify_nonce($nonce, self::NONCE_ACTION) ) {
			wp_send_json_error(['message' => 'Invalid nonce.'], 403);
		}

		$post_id = isset($_POST['post_id']) ? (int)$_POST['post_id'] : 0;
		if ( ! $post_id || ! current_user_can('edit_post', $post_id) ) {
			wp_send_json_error(['message' => 'Insufficient permissions.'], 403);
		}

		$post = get_post($post_id);
		if ( ! $post ) {
			wp_send_json_error(['message' => 'Invalid post.'], 400);
		}

		if ( ! $this->post_type_is_enabled($post->post_type) ) {
			wp_send_json_error(['message' => 'This post type is not enabled for AI drafting.'], 400);
		}

		$s = $this->get_settings();
		if ( empty($s['api_key']) ) {
			wp_send_json_error(['message' => 'Missing OpenAI API key (Plugins → KO – AI Draft Generator → Settings).'], 400);
		}

		$topic    = isset($_POST['topic']) ? wp_kses_post((string)$_POST['topic']) : '';
		$tone     = isset($_POST['tone']) ? sanitize_text_field((string)$_POST['tone']) : 'professional';
		$length   = isset($_POST['length']) ? sanitize_text_field((string)$_POST['length']) : 'medium';
		$keywords = isset($_POST['keywords']) ? sanitize_text_field((string)$_POST['keywords']) : '';
		$use_existing = ! empty($_POST['use_existing']);

		$word_targets = [
			'short'  => '400–700 words',
			'medium' => '800–1200 words',
			'long'   => '1400–2000 words',
		];
		$target = $word_targets[$length] ?? $word_targets['medium'];

		$instructions = "Output ONLY valid HTML for the WordPress editor (headings, paragraphs, lists). Do not output markdown or code fences. Do not wrap in <html> or <body>.";

		$parts = [];
		$parts[] = "Write a draft for initial ideation (not final copy).";
		$parts[] = "TOPIC: {$topic}";
		$parts[] = "TONE: {$tone}";
		$parts[] = "LENGTH: {$target}";
		if ($keywords) $parts[] = "KEYWORDS TO INCLUDE NATURALLY: {$keywords}";
		$parts[] = "Use clear H2 sections. Include a short intro and a concise conclusion.";

		if ($use_existing) {
			$current = wp_strip_all_tags( (string) $post->post_content );
			if ($current) {
				$parts[] = "CURRENT DRAFT (use as context, improve/expand, keep intent):";
				$parts[] = $current;
			}
		}

		$input = implode("\n\n", $parts);

		$text = $this->call_openai_responses_api($s, $instructions, $input);
		if ( is_wp_error($text) ) {
			wp_send_json_error(['message' => $text->get_error_message()], 500);
		}

		wp_send_json_success(['text' => $text]);
	}

	/* -----------------------
	 * AJAX: apply (server-side)
	 * --------------------- */
	public function ajax_apply() {
		if ( ! $this->user_is_allowed() ) {
			wp_send_json_error(['message' => 'Not allowed.'], 403);
		}

		$nonce = isset($_POST['nonce']) ? sanitize_text_field((string)$_POST['nonce']) : '';
		if ( ! wp_verify_nonce($nonce, self::NONCE_ACTION) ) {
			wp_send_json_error(['message' => 'Invalid nonce.'], 403);
		}

		$post_id = isset($_POST['post_id']) ? (int)$_POST['post_id'] : 0;
		if ( ! $post_id || ! current_user_can('edit_post', $post_id) ) {
			wp_send_json_error(['message' => 'Insufficient permissions.'], 403);
		}

		$post = get_post($post_id);
		if ( ! $post ) {
			wp_send_json_error(['message' => 'Invalid post.'], 400);
		}

		if ( ! $this->post_type_is_enabled($post->post_type) ) {
			wp_send_json_error(['message' => 'This post type is not enabled for AI drafting.'], 400);
		}

		$html = isset($_POST['html']) ? (string) $_POST['html'] : '';
		$html = wp_kses_post($html);

		if ( trim($html) === '' ) {
			wp_send_json_error(['message' => 'Empty content.'], 400);
		}

		$new_content = $html;

		$update = wp_update_post([
			'ID'           => $post_id,
			'post_content' => $new_content,
		], true);

		if ( is_wp_error($update) ) {
			wp_send_json_error(['message' => $update->get_error_message()], 500);
		}

		wp_send_json_success([
			'ok'       => true,
			'redirect' => get_edit_post_link($post_id, 'raw'),
		]);
	}

	/* -----------------------
	 * OpenAI call (Responses API)
	 * --------------------- */
	private function call_openai_responses_api($settings, $instructions, $input) {
		$endpoint = 'https://api.openai.com/v1/responses';

		$payload = [
			'model' => $settings['model'],
			'instructions' => $instructions,
			'input' => $input,
			'temperature' => (float)$settings['temperature'],
			'max_output_tokens' => (int)$settings['max_output_tokens'],
		];

		$args = [
			'headers' => [
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $settings['api_key'],
			],
			'timeout' => (int)$settings['timeout'],
			'body'    => wp_json_encode($payload),
		];

		$response = wp_remote_post($endpoint, $args);
		if ( is_wp_error($response) ) return $response;

		$code = (int) wp_remote_retrieve_response_code($response);
		$body = (string) wp_remote_retrieve_body($response);
		$json = json_decode($body, true);

		if ( $code < 200 || $code >= 300 ) {
			$msg = 'OpenAI API error.';
			if ( is_array($json) && isset($json['error']['message']) ) $msg = $json['error']['message'];
			return new WP_Error('ko_ai_openai_error', $msg);
		}

		$text = '';
		if ( is_array($json) && isset($json['output']) && is_array($json['output']) ) {
			foreach ($json['output'] as $item) {
				if ( isset($item['content']) && is_array($item['content']) ) {
					foreach ($item['content'] as $c) {
						if ( isset($c['type']) && $c['type'] === 'output_text' && isset($c['text']) ) {
							$text .= (string) $c['text'];
						}
					}
				}
			}
		}

		$text = trim($text);
		if ( $text === '' ) return new WP_Error('ko_ai_empty', 'No text returned from the model.');

		return $text;
	}
}

new KO_AI_Draft_Generator();
