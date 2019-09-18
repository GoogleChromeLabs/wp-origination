<?php
/**
 * The main template file.
 *
 * @package Google\WP_Origination
 */

/**
 * Can't use get_header() because TEMPLATEPATH can't be redefined.
 */
require __DIR__ . '/header.php';

?>
<?php while ( have_posts() ) : ?>
	<?php the_post(); ?>
	<article id="post-<?php the_ID(); ?>">
		<h1 class="entry-title"><?php the_title(); ?></h1>
		<div class="entry-excerpt">
			<?php the_excerpt(); ?>
		</div>
		<div class="entry-content">
			<?php the_content(); ?>
		</div>
	</article>
<?php endwhile; ?>
<?php wp_reset_postdata(); ?>

<?php

/**
 * Can't use get_sidebar() because TEMPLATEPATH can't be redefined.
 */
require __DIR__ . '/sidebar.php';

/**
 * Can't use get_footer() because TEMPLATEPATH can't be redefined.
 */
require __DIR__ . '/footer.php';
