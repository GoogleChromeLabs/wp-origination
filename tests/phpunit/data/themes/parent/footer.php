<?php
/**
 * The template for displaying the footer
 *
 * @package Google\WP_Origination
 */

?>
		</main>

		<footer id="colophon" class="site-footer">
			<a href="<?php echo esc_url( __( 'https://wordpress.org/', 'parent' ) ); ?>">
				<?php
				/* translators: %s: CMS name, i.e. WordPress. */
				printf( esc_html__( 'Proudly powered by %s', 'parent' ), 'WordPress' );
				?>
			</a>
		</footer><!-- #colophon -->

		<?php wp_footer(); ?>

	</body>
</html>
