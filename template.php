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
