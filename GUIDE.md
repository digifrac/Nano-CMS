# Nano CMS User Guide

A plain language guide to running a Nano CMS blog. This walks you through everything you do day to day: signing in, writing and publishing posts, adding images, organising categories, and keeping the blog tidy. It assumes the blog is already installed on your server. If it is not installed yet, that is a separate job covered in INSTALL.md.

This guide is written for the person who publishes the blog. You should be comfortable writing in Markdown, which is the simple text formatting the editor uses. You do not need to know any other code.

> An HTML version of this guide, ready to host or hand to clients, is in GUIDE.html.

---

## How a Nano CMS blog is laid out

A Nano CMS blog has three simple ideas behind it. Understanding them makes everything else easy.

**Posts are simple text files.** Every article you write is saved as a plain Markdown file on the server. There is no database. This keeps the blog fast, safe, and easy to back up.

**Categories group your posts.** Every post belongs to one category, for example News, Guides, or Reviews. The blog homepage shows your categories as a grid. A visitor picks a topic, then reads the articles inside it.

**The admin is removable.** You write and publish through a private admin area. When you finish, the admin can be taken off the server entirely, so there is nothing to break into while you are not using it. Putting it back is how you publish again. Your developer usually handles uploading and removing the admin.

---

## Signing in

Your admin lives at your blog address followed by `/admin`. For example, if your blog is at `example.com/blog`, your admin is at `example.com/blog/admin`.

1. Open that address in your browser.
2. Enter the password you set during setup.
3. You land on the Dashboard.

A few things worth knowing:

* The admin only works over a secure `https` connection. This is deliberate and protects your password.
* If the admin address shows a setup page or simply does not load, the admin folder is probably not on the server right now. Ask your developer to upload it so you can publish.
* Your password is set when the blog is first installed. If you ever need it changed, that is a quick job for your developer.

---

## A tour of the admin

Down the side of the admin you will find these areas:

| Area | What it is for |
|------|----------------|
| **Dashboard** | Your starting point. Shows how many posts are published, how many are drafts, and a health check that confirms the blog is set up correctly. |
| **Posts** | The list of all your articles. Write, edit, and delete posts here. |
| **Categories** | The topics your posts sit under. Give each one a name, description, and image. |
| **Media** | A file browser for every image you have uploaded. |
| **Settings** | Blog wide options: name, web address, author, layout, and image sizes. |
| **Licence** | Where you paste a licence key to remove the small footer credit. Optional. |
| **Help** | A short reference card built into the admin, including a Markdown cheat sheet. |

The rest of this guide goes through these in the order you will actually use them.

---

## A quick word on Markdown

The body of every post is written in Markdown. It is just normal text with a few simple marks for formatting. You do not need to learn it all at once. Here are the pieces you will use most:

| You type | You get |
|----------|---------|
| `## A heading` | A section heading. More hashes mean a smaller heading. |
| `**bold text**` | **bold text** |
| `*italic text*` | *italic text* |
| `[link text](https://example.com)` | A clickable link |
| `- one item` on each line | A bullet list |
| `1. one item` on each line | A numbered list |
| `> a quote` | An indented quotation |
| A blank line | Starts a new paragraph |

For safety, the editor strips out any raw web code you might paste in, so the published page only ever contains the formatting above. The built in **Help** page has the full cheat sheet.

### Embedding a video

You can drop in a YouTube or Vimeo video by putting one of these on its own line, using the video's id:

```
[video:youtube:VIDEO_ID]
[video:vimeo:VIDEO_ID]
```

The id is the short code at the end of the video's web address.

---

## Step one: set up your categories

It is worth creating your categories before you write many posts, so each article has a tidy home to go in.

1. Go to **Categories** and choose to add a new one.
2. Fill in the fields:

| Field | What to put |
|-------|-------------|
| **Name** | The display name readers see, for example `Guides`. |
| **Slug** | The short web friendly version, for example `guides`. Lowercase letters, numbers, and dashes only. This becomes part of the web address. |
| **Description** | A short paragraph shown at the top of the category page. Written in Markdown. Optional but good for readers and search engines. |
| **Image** | A picture shown on the category card on the homepage and as a banner on the category page. Optional. |
| **Sort order** | A number that decides where this category sits among the others. Lower numbers come first. |

3. Save.

You do not strictly have to create category records in advance. If you simply write a post and give it a new category name, that category will appear on its own. Creating a proper record just lets you add a nice name, description, and image to it.

---

## Step two: write a post

This is the heart of the blog and the thing you will do most.

1. Go to **Posts** and choose to write a new one.
2. Fill in the details around the article. These are the fields the editor asks for:

| Field | Required | What to put |
|-------|----------|-------------|
| **Title** | Yes | The headline of the article. Aim for under sixty characters so it reads well in search results. |
| **Slug** | Yes | The web friendly version of the title, in lowercase with dashes, for example `how-to-repot-a-fern`. This becomes the post's web address. |
| **Date** | Yes | The publish date, in the form `YYYY-MM-DD`. |
| **Updated** | No | A later date you can show when you revise a post. It is set for you when you change a post's content. Clear it for tiny tweaks you do not want flagged. |
| **Category** | Yes | One category for the post. As you type, existing categories suggest themselves. |
| **Description** | Yes | A one sentence summary, around 150 characters. This is what search engines show under the title, so make it inviting. |
| **Body** | Yes | The article itself, written in Markdown. |
| **Hero image** | No | A large picture shown at the top of the article. |
| **Card image** | No | A separate, smaller picture used only on the article card in listings. Leave it blank to reuse the hero image. |
| **Image description** | No | A short description of the picture for search engines and screen readers. Always worth adding. |
| **Draft** | No | Tick this to keep the post hidden while you work on it. Untick it to publish. |

3. Save.

A good habit: save the post as a **Draft** first, read it back on the preview, then untick Draft to publish when you are happy. Drafts are invisible to the public but you can preview them while signed in.

---

## Step three: add images

Pictures make posts inviting, and Nano CMS handles them carefully.

### Uploading

You add images through the **Media** area or straight from the post editor. Allowed types are JPG, PNG, GIF, and WebP, up to five megabytes each. Every upload is processed on the server for safety, and given a fresh tidy filename automatically.

**Upload large, and the blog will shrink it for you.** A generous source picture, say 1200 by 800 or bigger, gives the best results. The blog never stretches a small image up, so avoid tiny files.

### The Media area

The Media area is a simple file browser for all your pictures. You can:

* Create folders to keep things organised. Two folders always exist: one for article images and one for category images.
* Drag and drop files in to upload them.
* Drag a file into a folder to move it, or rename it.
* See an "unused" badge on any picture that no post currently uses, so you can clear out clutter safely. Moving or renaming a picture updates the posts that use it automatically.

### Fitting a picture

Each hero image, each card image, and each category image carries its own display settings, so one picture never forces an awkward crop on another. You can set:

| Setting | What it does |
|---------|--------------|
| **Fit: Cover** | Fills the frame and trims any overflow. Best for photographs where losing a little around the edges is fine. This is the default. |
| **Fit: Contain** | Shows the entire picture with nothing trimmed. Any spare space shows the background colour. Best for logos or anything that must be seen in full. |
| **Focal point** | When Cover trims a picture, this chooses which part to keep: upper centre, centre, top, bottom, left, or right. |
| **Background colour** | A colour shown behind a Contain image or through the see through parts of a PNG. A hex colour, or blank for none. |

Because the blog resizes on demand, you can change a picture's fit or focal point at any time and see the result straight away, with no need to upload it again.

### A quick rule of thumb

* Landscape pictures, roughly three by two, are the safe default for cards.
* Use **Cover** for normal photos.
* Use **Contain** with a background colour for logos or anything that must not be cut.
* Always add a short, honest image description. It helps search engines and readers using screen readers.

---

## Step four: publish and check

When the words and pictures are ready, untick **Draft** and save. The post is now live, and the blog's sitemap and feed update themselves automatically.

Visit your blog in a normal browser tab and check:

* The post appears under the right category.
* The headline, picture, and links look right.
* The article reads the way you intended.

If something is off, go back into the admin, fix it, and save again. Changes are live immediately.

---

## Featuring your best posts

A few options let you control what readers notice first on the homepage.

**Hero** lifts a single standout article into a large banner at the top of the homepage. Only one post can be the hero at a time.

**Featured** highlights a small set of posts in a row above the category grid. Use it for pieces you want to keep visible.

**Draft versus published** is your on and off switch. A draft is saved safely but invisible to readers, ready for the day you publish it.

Your homepage then reads as a tidy landing page: an optional hero, a row of featured articles, and a grid of categories for readers to explore.

---

## Settings

**Settings** holds the blog wide options. You will set most of these once at the start and rarely touch them again.

### Site

* **Site name**: the name of your blog, shown in the page title, the feed, and social media previews.
* **Base URL**: the full web address the blog is served at, for example `https://example.com/blog`, with no trailing slash. This matters: every link, image, and feed is built from it. If links ever drop the `/blog` part or category pages fail to load, this is the setting to check.
* **Author name**: the default author credited on posts.
* **Publisher name**: your brand or business name, used in the data search engines read.
* **Publisher logo**: an optional web address for your logo, used in search rich results.
* **Posts per page**: how many articles show on a category page before it splits into further pages.

### Layout

* **Categories per row** and **Articles per row**: choose 3 or 4 across on wide screens. Narrower screens automatically show fewer so nothing is ever cramped.

### Image sizes

* **Article thumbnail size**: the shape and size of the small pictures on article cards. The defaults suit most blogs. A common choice is 600 by 400.
* **Category image size**: the same idea for category cards, set separately so the two grids can be tuned independently.

Changing these sizes affects pictures uploaded from then on. The card shapes update on the public side straight away.

---

## Removing the footer credit (optional)

By default, Nano CMS shows a small "Powered by Nano CMS" line in the footer of the pages it renders. This is normal and completely fine to leave in place.

If you would rather remove it, you can buy a licence for your domain and paste the key into the **Licence** area of the admin. The footer credit then disappears. The check happens entirely on your own server, with no tracking and no contact with anyone. You can buy a licence from the Nano CMS website.

---

## Going live and staying safe

When you have finished a round of writing, the safest thing is to have the admin folder taken off the server. Your blog keeps working perfectly without it: readers browse and read every article exactly as before. The only thing that goes away is the private admin area, and with it any way for someone to try the password while you are not using it.

When you want to publish again, the admin folder goes back on, you sign in, you work, and it comes off again. Your developer usually handles this part, so a typical routine is:

1. Ask your developer to put the admin back.
2. Write and publish.
3. Tell them you are done so they can take it off again.

---

## Backups

Your entire blog is a set of plain files on the server: the posts and the images. There is no database. A simple scheduled copy of the posts folder, the media folder, and the config folder to a safe place is all the backup you need. Your developer sets this up once, and restoring is just copying the files back. It is worth confirming with them that backups are running.

---

## Common questions

**Do I have to know how to code?**
No. You only need to be comfortable writing in Markdown, which is plain text with a few simple marks. The Help page inside the admin has a one page cheat sheet.

**Can readers leave comments?**
No. Nano CMS is built purely for publishing articles, with no comments, accounts, or logins for readers. This keeps it fast and safe. If you want discussion, a separate embedded comment service is the usual route.

**I published a post but it is not showing.**
Check that **Draft** is unticked, and that it sits under a category that exists. Then refresh the page in your browser.

**My links or images are broken after moving the blog.**
This is almost always the **Base URL** in Settings pointing at the wrong address. Set it to the blog's real web address with no trailing slash, and save.

**The admin will not load.**
The admin folder is most likely off the server for safety. Ask your developer to put it back so you can sign in.

**Can I schedule a post for the future?**
No. A post is either a draft or published. To publish later, leave it as a draft and untick Draft on the day.

---

## A simple publishing routine

1. Have the admin put back on the server.
2. Sign in.
3. Write your post as a draft, with a hero image and a good description.
4. Preview it and read it back.
5. Untick Draft to publish.
6. Sign out and have the admin taken off again.

That is the whole job. Set your categories up once, write posts as you go, and let the blog stay quiet, fast, and safe in between.

---

## Where to go next

* **INSTALL.md**: how the blog is first set up on a server.
* **FORMAT.md**: the exact shape of the post files behind the scenes, for the curious or technical.
* **CATEGORIES.md**: how the category landing pages work.
* **CHANGELOG.md**: what changed in each version.
* The built in **Help** page inside the admin: a Markdown cheat sheet and quick reference you can read while you work.
