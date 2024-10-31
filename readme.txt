=== Post to Flarum ===
Contributors: malago
Tags: forum, flarum
Requires at least: 4.9
Tested up to: 5.5
Stable tag: trunk
Requires PHP: 7.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automatically create and link Wordpress posts to a Flarum discussion.

== Description ==
This plugin for WordPress will create a new discussion on your linked Flarum forum for each blog post.

## Installation

Copy the folder to your plugins directory in WordPress: /wp-contents/plugins/

## Usage
- Create a token on your Flarum database ([see instructions](https://github.com/flagrow/flarum-api-client#configuration))
- Introduce the absolute URL of your Flarum forum and activate the plugin
- Optional: Add the *slug* of the tag that every new post should be assigned to. If tag requirements are not meet, the plugin will fail to create a discussion
- Optional: Activate the link option to make the WordPress post link to the forum discussion

## Requirements
- WordPress 4.9 or later
- Flarum 0.1.0-beta9 or later

## Optional components
- If you want to create a link on Wordpress to the forum post, you need the [Page links to](https://wordpress.org/plugins/page-links-to/) plugin for WordPress.
- If you want to be able to change the author of the Flarum discussion, you need the [Author Change extension](https://github.com/clarkwinkelmann/flarum-ext-author-change/) installed on your Flarum forum. Otherwise, the author will be the one that created the Flarum token.


== Changelog ==
List of changes.
= 0.3.4 =
* Fixed bugs with quotes on the custom tags

= 0.3.3 =
* Tried to fix bug with tags

= 0.3.2 =
* Tried to fix bug with doing save action more than once

= 0.3.1 =
* Fixed bug with postmeta erasing other postmeta

= 0.3.0 =
* Made compatible with classic editor
* Added some error catching

= 0.2.1 =
* Changed `apply_filter` for `do_shortcode` to avoid iframes

= 0.2.0 =
* The plugin has been completely rewritten and now it uses the Flarum API instead of making changes directly in the database