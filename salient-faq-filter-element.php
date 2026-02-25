<?php
/**
 * Plugin Name: Salient - FAQ Filter (WPBakery Element)
 * Description: WPBakery element for Salient that displays FAQ CPT posts with a category dropdown filter, AJAX loading, caching, and FAQPage schema.
 * Version: 1.0.1
 * Author: Giant Creative Inc
 *
 * @package Salient_FAQ_Filter_Element
 */

defined('ABSPATH') || exit;

if (!class_exists('Salient_FAQ_Filter_Element')) {

	/**
	 * Class Salient_FAQ_Filter_Element
	 *
	 * Provides:
	 * - Shortcode [salient_faq_filter]
	 * - WPBakery element mapping
	 * - Conditional asset loading (only when shortcode is rendered)
	 * - AJAX endpoint to fetch FAQs for a selected taxonomy term
	 * - Server-side caching for fast category switching
	 * - AODA-friendly accordion markup
	 * - FAQPage JSON-LD schema output on initial render (does not update on filter change)
	 */
	final class Salient_FAQ_Filter_Element {

		/**
		 * Plugin version.
		 */
		const VERSION = '1.0.1';

		/**
		 * Shortcode tag.
		 */
		const SHORTCODE = 'salient_faq_filter';

		/**
		 * CPT + Taxonomy slugs.
		 */
		const CPT = 'faq';
		const TAX = 'faq-category';

		/**
		 * Asset handles.
		 */
		const STYLE_HANDLE  = 'sffe-faq-filter';
		const SCRIPT_HANDLE = 'sffe-faq-filter';

		/**
		 * Internal flag used to know shortcode was rendered on the page
		 * so we can conditionally enqueue assets.
		 *
		 * @var bool
		 */
		private static $did_render = false;

		/**
		 * Boot the plugin.
		 */
		public static function init() {
			add_shortcode(self::SHORTCODE, array(__CLASS__, 'render_shortcode'));

			// WPBakery mapping (safe even if WPBakery is not installed).
			add_action('vc_before_init', array(__CLASS__, 'register_wpbakery_element'));

			// AJAX endpoints for logged-in + logged-out users.
			add_action('wp_ajax_sffe_get_faqs', array(__CLASS__, 'ajax_get_faqs'));
			add_action('wp_ajax_nopriv_sffe_get_faqs', array(__CLASS__, 'ajax_get_faqs'));
		}

		/**
		 * Register WPBakery element.
		 *
		 * @return void
		 */
		public static function register_wpbakery_element() {
			if (!function_exists('vc_map')) {
				return;
			}

			vc_map(array(
				'name'        => __('FAQ Filter', 'sffe'),
				'base'        => self::SHORTCODE,
				'category'    => __('Salient Elements', 'sffe'),
				'description' => __('Filterable FAQ list from CPT "faq" by taxonomy "faq-category"', 'sffe'),
				'params'      => array(
					array(
						'type'        => 'textfield',
						'heading'     => __('Heading (optional)', 'sffe'),
						'param_name'  => 'heading',
						'description' => __('Optional label above the filter area.', 'sffe'),
					),
					array(
						'type'        => 'dropdown',
						'heading'     => __('Default Selected Category', 'sffe'),
						'param_name'  => 'default_term',
						'value'       => self::get_terms_for_vc_dropdown(),
						'description' => __('Which category is selected on first load. "All" is always available.', 'sffe'),
					),
					array(
						'type'        => 'textfield',
						'heading'     => __('Max FAQs in Schema', 'sffe'),
						'param_name'  => 'schema_max',
						'value'       => '100',
						'description' => __('Limit FAQPage JSON-LD size. Schema is rendered once on initial load only.', 'sffe'),
					),
				),
			));
		}

		/**
		 * Helper: Build options for WPBakery dropdown.
		 * We include All + any term that actually contains at least one FAQ post.
		 *
		 * @return array<string,string> label => value
		 */
		private static function get_terms_for_vc_dropdown() {
			$options = array(
				__('All', 'sffe') => 'all',
			);

			$terms = get_terms(array(
				'taxonomy'   => self::TAX,
				'hide_empty' => true, // only show terms that have posts
			));

			if (!is_wp_error($terms) && !empty($terms)) {
				foreach ($terms as $term) {
					$options[$term->name] = (string) $term->term_id;
				}
			}

			return $options;
		}

    /**
     * Enqueue CSS/JS only when the shortcode is rendered.
     *
     * This is the most reliable approach because wp_enqueue_scripts runs
     * before the_content/shortcodes execute.
     *
     * @return void
     */
    private static function enqueue_assets() {
      $plugin_url = plugin_dir_url(__FILE__);

      wp_register_style(
        self::STYLE_HANDLE,
        $plugin_url . 'assets/faq-filter.css',
        array(),
        self::VERSION
      );

      wp_register_script(
        self::SCRIPT_HANDLE,
        $plugin_url . 'assets/faq-filter.js',
        array(),
        self::VERSION,
        true
      );

      wp_enqueue_style(self::STYLE_HANDLE);
      wp_enqueue_script(self::SCRIPT_HANDLE);

      // Provide AJAX URL + nonce to the script.
      wp_localize_script(self::SCRIPT_HANDLE, 'SFFE_FAQ_FILTER', array(
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('sffe_faq_filter_nonce'),
      ));
    }

		/**
		 * Conditionally enqueue assets.
		 *
		 * This runs on wp_enqueue_scripts. We only enqueue if:
		 * - The shortcode has already rendered (self::$did_render === true)
		 *
		 * Why this approach?
		 * - It guarantees CSS/JS only loads when the element is on the page.
		 * - It also works even when the element is inserted by WPBakery shortcodes.
		 *
		 * @return void
		 */
		public static function maybe_enqueue_assets() {
			if (!self::$did_render) {
				return;
			}

			$plugin_url = plugin_dir_url(__FILE__);

			wp_register_style(
				self::STYLE_HANDLE,
				$plugin_url . 'assets/faq-filter.css',
				array(),
				self::VERSION
			);

			wp_register_script(
				self::SCRIPT_HANDLE,
				$plugin_url . 'assets/faq-filter.js',
				array(),
				self::VERSION,
				true
			);

			wp_enqueue_style(self::STYLE_HANDLE);
			wp_enqueue_script(self::SCRIPT_HANDLE);

			// Localize data for AJAX (nonce + URL).
			wp_localize_script(self::SCRIPT_HANDLE, 'SFFE_FAQ_FILTER', array(
				'ajaxUrl' => admin_url('admin-ajax.php'),
				'nonce'   => wp_create_nonce('sffe_faq_filter_nonce'),
			));
		}

		/**
		 * Render shortcode output.
		 *
		 * Attributes:
		 * - heading (string)
		 * - default_term ("all" or term_id)
		 * - schema_max (int)
		 *
		 * @param array<string,mixed> $atts Shortcode attributes.
		 * @return string HTML output.
		 */
		public static function render_shortcode($atts) {
			self::$did_render = true;
      self::enqueue_assets();

			$atts = shortcode_atts(array(
				'heading'      => '',
				'default_term' => 'all',
				'schema_max'   => '100',
			), $atts, self::SHORTCODE);

			$instance_id = 'sffe-' . wp_generate_uuid4();

			// Terms that have posts.
			$terms = get_terms(array(
				'taxonomy'   => self::TAX,
				'hide_empty' => true,
			));
			if (is_wp_error($terms)) {
				$terms = array();
			}

			// Normalize default selection.
			$default_term = $atts['default_term'];
			$default_term = ($default_term === 'all') ? 'all' : absint($default_term);

			// If the chosen term doesn’t exist in the list, fall back to All.
			if ($default_term !== 'all') {
				$found = false;
				foreach ($terms as $t) {
					if ((int) $t->term_id === (int) $default_term) {
						$found = true;
						break;
					}
				}
				if (!$found) {
					$default_term = 'all';
				}
			}

			// Initial FAQs HTML for default category (server-side render for first paint + SEO friendliness).
			$initial = self::get_faqs_markup_cached($default_term, $instance_id);

			// The title shown under the dropdown.
			$current_title = 'All';
			if ($default_term !== 'all') {
				foreach ($terms as $t) {
					if ((int) $t->term_id === (int) $default_term) {
						$current_title = $t->name;
						break;
					}
				}
			}

			// Schema: only output once on initial load. We’ll output FAQPage schema for ALL FAQs
			// (independent of selected filter) because user requested schema doesn't need updating.
			$schema_max = max(1, (int) $atts['schema_max']);
			$schema_json = self::build_faq_schema_jsonld($schema_max);

			ob_start();
			?>
			<div class="sffe-faq-filter" id="<?php echo esc_attr($instance_id); ?>"
				data-default-term="<?php echo esc_attr($default_term === 'all' ? 'all' : (string) (int) $default_term); ?>">

				<?php if (!empty($atts['heading'])) : ?>
					<div class="sffe-faq-filter__heading">
						<?php echo esc_html($atts['heading']); ?>
					</div>
				<?php endif; ?>

				<div class="sffe-faq-filter__top">
					<div class="sffe-faq-filter__control">
						<label class="sffe-faq-filter__label" for="<?php echo esc_attr($instance_id . '-select'); ?>">
							<?php echo esc_html__('Filter By:', 'sffe'); ?>
						</label>

						<select class="sffe-faq-filter__select" id="<?php echo esc_attr($instance_id . '-select'); ?>">
							<option value="all" <?php selected($default_term, 'all'); ?>>
								<?php echo esc_html__('All', 'sffe'); ?>
							</option>

							<?php foreach ($terms as $term) : ?>
								<option value="<?php echo esc_attr((string) (int) $term->term_id); ?>"
									<?php selected((int) $default_term, (int) $term->term_id); ?>>
									<?php echo esc_html($term->name); ?>
								</option>
							<?php endforeach; ?>
						</select>

						<span class="sffe-faq-filter__status" aria-live="polite" aria-atomic="true"></span>
					</div>

					<div class="sffe-faq-filter__current">
						<span class="sffe-faq-filter__line" aria-hidden="true"></span>
						<h2 class="sffe-faq-filter__title">
							<?php echo esc_html($current_title); ?>
						</h2>
						<span class="sffe-faq-filter__line" aria-hidden="true"></span>
					</div>
				</div>

				<div class="sffe-faq-filter__results" data-results>
					<?php echo $initial; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>

				<?php if (!empty($schema_json)) : ?>
					<script type="application/ld+json">
						<?php echo $schema_json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</script>
				<?php endif; ?>
			</div>
			<?php
			return ob_get_clean();
		}

		/**
		 * AJAX handler: returns FAQ markup for a given category (term_id or "all").
		 *
		 * Security:
		 * - Requires a nonce
		 *
		 * Performance:
		 * - Uses server-side caching via transients/object cache
		 *
		 * @return void Outputs JSON.
		 */
		public static function ajax_get_faqs() {
			check_ajax_referer('sffe_faq_filter_nonce', 'nonce');

			$term_raw = isset($_POST['term']) ? sanitize_text_field(wp_unslash($_POST['term'])) : 'all';
			$term = ($term_raw === 'all') ? 'all' : absint($term_raw);

			// Instance id is optional; used only to make unique accordion IDs if desired.
			$instance_id = isset($_POST['instanceId']) ? sanitize_text_field(wp_unslash($_POST['instanceId'])) : 'sffe-ajax';

			$markup = self::get_faqs_markup_cached($term, $instance_id);

			wp_send_json_success(array(
				'html' => $markup,
			));
		}

		/**
		 * Get FAQ list markup with caching.
		 *
		 * Cache key includes:
		 * - term selection ("all" or term_id)
		 * - current locale
		 * - a "version" string that you can bump if markup changes
		 *
		 * Invalidation:
		 * - Easiest: keep TTL moderate (e.g. 6 hours)
		 * - Optional: you can add hooks on save_post / edited_terms to delete transients
		 *
		 * @param string|int $term "all" or term_id
		 * @param string     $instance_id Unique instance id for accordion IDs.
		 * @return string HTML
		 */
		private static function get_faqs_markup_cached($term, $instance_id) {
			$locale = function_exists('determine_locale') ? determine_locale() : get_locale();
			$ver = 'v1';

			$term_key = ($term === 'all') ? 'all' : (string) (int) $term;
			$cache_key = 'sffe_faq_markup_' . md5($ver . '|' . $term_key . '|' . $locale);

			// Try object cache first, then transient.
			$cached = wp_cache_get($cache_key, 'sffe');
			if (false !== $cached && is_string($cached)) {
				return $cached;
			}

			$cached = get_transient($cache_key);
			if (false !== $cached && is_string($cached)) {
				// Warm object cache too.
				wp_cache_set($cache_key, $cached, 'sffe', 6 * HOUR_IN_SECONDS);
				return $cached;
			}

			$html = self::build_faqs_markup($term, $instance_id);

			// Store for fast switching.
			set_transient($cache_key, $html, 6 * HOUR_IN_SECONDS);
			wp_cache_set($cache_key, $html, 'sffe', 6 * HOUR_IN_SECONDS);

			return $html;
		}

		/**
		 * Build the FAQ accordion markup (AODA-friendly).
		 *
		 * Accessibility notes:
		 * - Each item uses a <button> to toggle a region
		 * - aria-expanded updates via JS
		 * - aria-controls links button to panel
		 * - Panel uses role="region" and aria-labelledby to connect back
		 *
		 * @param string|int $term "all" or term_id
		 * @param string     $instance_id Unique instance id used to generate unique IDs.
		 * @return string HTML
		 */
		private static function build_faqs_markup($term, $instance_id) {
			$args = array(
				'post_type'      => self::CPT,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'menu_order',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			);

			if ($term !== 'all') {
				$args['tax_query'] = array(
					array(
						'taxonomy' => self::TAX,
						'field'    => 'term_id',
						'terms'    => array((int) $term),
					),
				);
			}

			$q = new WP_Query($args);

			if (!$q->have_posts()) {
				return '<p class="sffe-faq-filter__empty">' . esc_html__('No FAQs found.', 'sffe') . '</p>';
			}

			ob_start();
			?>
			<div class="sffe-accordion" data-accordion>
				<?php
				$index = 0;
				while ($q->have_posts()) :
					$q->the_post();
					$index++;

					$post_id  = get_the_ID();
					$question = get_the_title($post_id);

					// Use the_content to respect WP formatting. We'll wrap inside our panel container.
					$answer_raw = get_the_content(null, false, $post_id);
					$answer     = apply_filters('the_content', $answer_raw);

					// Unique IDs per item (avoid collisions when multiple elements exist on the same page).
					$btn_id   = $instance_id . '-faq-btn-' . $post_id . '-' . $index;
					$panel_id = $instance_id . '-faq-panel-' . $post_id . '-' . $index;
					?>
					<div class="sffe-accordion__item">
						<h3 class="sffe-accordion__question">
							<button
								type="button"
								class="sffe-accordion__toggle"
								id="<?php echo esc_attr($btn_id); ?>"
								aria-expanded="false"
								aria-controls="<?php echo esc_attr($panel_id); ?>">
								<span class="sffe-accordion__qtext"><?php echo esc_html($question); ?></span>
								<span class="sffe-accordion__icon" aria-hidden="true"></span>
							</button>
						</h3>

						<div
							class="sffe-accordion__panel"
							id="<?php echo esc_attr($panel_id); ?>"
							role="region"
							aria-labelledby="<?php echo esc_attr($btn_id); ?>"
							hidden>
							<div class="sffe-accordion__answer">
								<?php echo $answer; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							</div>
						</div>
					</div>
				<?php endwhile; ?>
			</div>
			<?php
			wp_reset_postdata();

			return ob_get_clean();
		}

		/**
		 * Build FAQPage JSON-LD for ALL FAQs (does not update on filter change).
		 *
		 * We intentionally output schema for all FAQ posts to keep it stable regardless
		 * of filter selection, matching your requirement.
		 *
		 * @param int $max Max number of FAQ items in schema.
		 * @return string JSON (pretty printed) or empty string.
		 */
		private static function build_faq_schema_jsonld($max) {
			$args = array(
				'post_type'      => self::CPT,
				'post_status'    => 'publish',
				'posts_per_page' => $max,
				'orderby'        => 'menu_order',
				'order'          => 'ASC',
				'no_found_rows'  => true,
				'fields'         => 'ids',
			);

			$ids = get_posts($args);
			if (empty($ids) || is_wp_error($ids)) {
				return '';
			}

			$mainEntity = array();

			foreach ($ids as $post_id) {
				$q = get_the_title($post_id);
				$a_raw = get_post_field('post_content', $post_id);
				$a_html = apply_filters('the_content', $a_raw);

				// Schema answer should be plain text.
				$a_text = wp_strip_all_tags($a_html);
				$a_text = trim(preg_replace('/\s+/', ' ', $a_text));

				if ($q === '' || $a_text === '') {
					continue;
				}

				$mainEntity[] = array(
					'@type' => 'Question',
					'name'  => $q,
					'acceptedAnswer' => array(
						'@type' => 'Answer',
						'text'  => $a_text,
					),
				);
			}

			if (empty($mainEntity)) {
				return '';
			}

			$data = array(
				'@context'   => 'https://schema.org',
				'@type'      => 'FAQPage',
				'mainEntity' => $mainEntity,
			);

			return wp_json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
		}
	}

	Salient_FAQ_Filter_Element::init();
}