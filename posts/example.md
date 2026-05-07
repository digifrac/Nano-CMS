---
title: Example Post
slug: example-post
date: 2026-05-06
updated: 2026-05-07
category: web-design
description: An example post used to smoke-test the Nano CMS frontend parser and renderer.
image: 2026-05-06-a4f8b2.jpg
image_alt: Placeholder hero image for the example post
draft: false
---

# Welcome to Nano CMS

This file exists so the parser, the renderer, and the shortcode pipeline can
be exercised end-to-end without any client content present.

## Markdown features

- Lists work
- **Bold** and *italic* render
- `inline code` renders
- [Links](https://digitalfracture.co.uk) render

A code block:

```
echo "hello, nano";
```

## Safe mode check

The next line tries to smuggle a script tag and should be escaped:

<script>alert('xss')</script>

## Shortcodes

A YouTube embed on its own line:

[video:youtube:dQw4w9WgXcQ]

A Vimeo embed inline like [video:vimeo:123456789] should still expand.
