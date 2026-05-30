<?php
if (!defined('NANO_BOOTSTRAPPED')) {
    http_response_code(403);
    exit;
}

/**
 * Per-site HTML wrapper. EDIT PER DEPLOYMENT.
 *
 * Variables available (all already escaped or trusted HTML):
 *   $page_title       - <title> content
 *   $page_description - meta description value
 *   $meta_tags        - <link>/<meta>/<script> block (canonical, OG, JSON-LD)
 *   $content          - rendered post or post listing
 *
 * The marked paste-zone sections are intended to receive HTML pasted from
 * the client's existing static site so the blog inherits the host design.
 * See CLAUDE.md section 9.6 for the slot-in pattern.
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= $page_title ?></title>
  <meta name="description" content="<?= $page_description ?>">
  <?= $meta_tags ?>

  <!--
    BLOG STYLING - choose one mode:
      A) No CSS              - comment out both lines below
      B) Default neutral     - leave the first line uncommented
      C) Default + custom    - uncomment both
    Adjust paths if the blog is installed somewhere other than /blog/.
  -->
  <link rel="stylesheet" href="/blog/assets/nano.css">
  <!-- <link rel="stylesheet" href="/blog/assets/theme-custom.css"> -->

  <!-- Default web font (Inter), matching the Nano Cart storefront. nano.css's
       --nano-font-family points at it. To go system-font-only (zero third-party
       request), remove these three lines and reset --nano-font-family in
       theme-custom.css. -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">

  <!-- =========================================================== -->
  <!-- BEGIN: paste from client's static site <head> below          -->
  <!-- (link tags for CSS, fonts, favicon, analytics, etc.)         -->
  <!-- =========================================================== -->

  <!-- =========================================================== -->
  <!-- END: client site <head>                                      -->
  <!-- =========================================================== -->
</head>
<body>

  <!-- =========================================================== -->
  <!-- BEGIN: paste client's site header HTML below                 -->
  <!-- =========================================================== -->
  <!--
    Heads-up: Nano CMS's CSS is scoped to .nano-blog and deliberately does
    NOT set a global box-sizing or line-height reset, so it never restyles
    your existing site. Anything you paste in this header (and the footer
    zone below) is styled by YOUR site's CSS and inherits the browser
    defaults (box-sizing: content-box, line-height: normal), not the blog's.
    If your header uses max-width + padding, give it box-sizing: border-box;
    and set a line-height, or it can render a different size than you expect.
  -->

  <!-- =========================================================== -->
  <!-- END: client site header                                      -->
  <!-- =========================================================== -->

  <main class="nano-blog">
<?= $content ?>
  </main>

  <!-- =========================================================== -->
  <!-- BEGIN: paste client's site footer HTML below                 -->
  <!-- =========================================================== -->



  <!-- =========================================================== -->
  <!-- END: client site footer                                      -->
  <!-- =========================================================== -->

</body>
</html>
