# WP Dynamic Tags

> Create and manage unlimited dynamic tags as shortcodes for use in WordPress, Elementor, and other page builders.

[![Version](https://img.shields.io/badge/version-2.0.0-blue.svg)](https://github.com/yourusername/wp-dynamic-tags)
[![WordPress](https://img.shields.io/badge/WordPress-5.0%2B-brightgreen.svg)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-purple.svg)](https://php.net)
[![License](https://img.shields.io/badge/license-GPL%20v2%2B-red.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

---

## 📋 Description

**WP Dynamic Tags** is a powerful WordPress plugin that allows you to create unlimited dynamic content tags and use them as shortcodes throughout your website. Whether you're using the default WordPress editor, Elementor, or any other page builder, this plugin seamlessly integrates to provide dynamic, reusable content snippets.

Perfect for:
- 🏢 **Agencies** managing multiple client sites with reusable content
- 📝 **Content creators** who need consistent messaging across pages
- 🛒 **E-commerce sites** with frequently changing promotional content
- 🎨 **Page builder users** (Elementor, Beaver Builder, etc.)
- 🔧 **Developers** needing a flexible content management solution

---

## ✨ Key Features

### 🎯 Core Functionality
- **Unlimited Dynamic Tags** - Create as many tags as you need with custom content
- **Universal Shortcode Support** - Works with WordPress core, Elementor, and all major page builders
- **Dual Storage System** - Choose between WordPress post types or optimized custom database table
- **Smart Migration** - Seamlessly migrate between storage systems without data loss

### 📁 Organization & Management
- **Tag Groups (Taxonomy)** - Organize tags hierarchically with custom categories
- **Bulk Operations Manager** - Edit, delete, or modify multiple tags at once
- **Inline Editing** - Quick edit tags directly from the admin list view
- **Advanced Filtering** - Filter by groups, conflict status, usage, date, and content length

### 🔄 Dynamic Placeholders
Automatically inject real-time data into your tags:

**User Data:**
- `{user_name}` - Current user's username
- `{user_email}` - User's email address
- `{user_display_name}` - User's display name
- `{user_first_name}` / `{user_last_name}` - User's first/last name
- `{user_role}` - User's role

**Date & Time:**
- `{current_year}` - Current year
- `{current_month}` - Current month
- `{current_day}` - Current day
- `{current_date}` - Full date
- `{current_time}` - Current time
- `{current_datetime}` - Date and time

**Site Information:**
- `{site_name}` - Your site's name
- `{site_url}` - Site URL
- `{site_description}` - Site tagline/description
- `{admin_email}` - Admin email
- `{site_language}` - Site language
- `{site_charset}` - Character set

**Post Context:**
- `{post_title}` - Current post title
- `{post_id}` - Post ID
- `{post_author}` - Post author name
- `{post_date}` - Post publish date
- And more...

### 🔧 Special Shortcodes

#### `[dt_fallback]` - Fallback Handling
Display alternative content if a tag doesn't exist:
```
[dt_fallback tag="promo_text" default="No promotion available"]
```

#### `[dt_group]` - Group Display
Display all tags from a specific group:
```
[dt_group name="footer_links" separator=", "]
```

#### `[dt_random]` - Random Tag
Display a random tag from a group or all tags:
```
[dt_random group="testimonials"]
[dt_random] <!-- Random from all tags -->
```

#### `[dt_count]` - Tag Counter
Display the count of tags (optionally by group):
```
[dt_count] <!-- Total tags -->
[dt_count group="products"] <!-- Tags in group -->
[dt_count group="products" format="text"] <!-- "five" instead of "5" -->
```

#### `[dt_list]` - Tag List
Display a formatted list of tags:
```
[dt_list group="features" type="ul"]
[dt_list group="team" type="ol"]
```

### 🛡️ Conflict Resolution System
- **Automatic Detection** - Identifies duplicate tag names and shortcode conflicts
- **Smart Resolution** - Multiple strategies to resolve conflicts:
  - Group-based organization
  - ID prefix generation
  - Automatic merging options
- **Visual Indicators** - Clear admin UI showing conflict status
- **Bulk Resolution** - Resolve multiple conflicts at once

### 📤 Import/Export Capabilities
- **Multiple Formats**: JSON, CSV, XML, SQL
- **Selective Export** - Export all tags or by group
- **Conflict Handling** - Choose how to handle duplicates on import
- **Sample Files Included** - Get started quickly with examples

### ⚡ Performance Optimizations
- **Multi-level Caching** - WordPress transients, object cache, and static caching
- **Query Optimization** - Reduced database queries with smart caching
- **Lazy Loading** - Load tags on-demand for better performance
- **Cache Invalidation** - Automatic cache clearing on content updates
- **Performance Monitoring** - Built-in performance statistics tracking

---

## 📦 Installation

### Method 1: WordPress Admin
1. Download the plugin ZIP file
2. Go to **Plugins > Add New** in your WordPress admin
3. Click **Upload Plugin** and select the ZIP file
4. Click **Install Now**
5. Activate the plugin

### Method 2: Manual Installation
1. Download and extract the plugin files
2. Upload the `wp-dynamic-tags-v2` folder to `/wp-content/plugins/`
3. Activate the plugin through the **Plugins** menu in WordPress

### Method 3: FTP
1. Extract the plugin ZIP file
2. Upload via FTP to `/wp-content/plugins/wp-dynamic-tags-v2`
3. Activate through WordPress admin

### Requirements
- ✅ WordPress 5.0 or higher
- ✅ PHP 7.4 or higher
- ✅ MySQL 5.6 or higher

---

## 🚀 Getting Started

### Creating Your First Dynamic Tag

1. **Navigate to Dynamic Tags**
   - Go to **Dynamic Tags > All Tags** or **Dynamic Tags > Quick Create**

2. **Create a New Tag**
   - **Tag Name**: Enter a unique name (e.g., "company_phone")
   - **Tag Content**: Enter the content (e.g., "1-800-555-0100")
   - **Assign to Group** (optional): Organize with tag groups

3. **Use the Shortcode**
   - Use `[company_phone]` anywhere on your site
   - Works in posts, pages, widgets, and page builders

### Using Dynamic Placeholders

Create a personalized greeting tag:
```
Content: Hello {user_display_name}, welcome back!
Shortcode: [greeting]
Output: Hello John Doe, welcome back!
```

Create a copyright tag:
```
Content: © {current_year} {site_name}. All rights reserved.
Shortcode: [copyright]
Output: © 2025 My Website. All rights reserved.
```

---

## 📖 Usage Guide

### Tag Groups / Organization

**Creating Tag Groups:**
1. Go to **Dynamic Tags > Tag Groups**
2. Click **Add New Group**
3. Enter group name and description
4. Organize tags by assigning them to groups

**Benefits of Tag Groups:**
- Logical organization of related tags
- Bulk operations on grouped tags
- Group-specific shortcodes (`[dt_group]`, `[dt_random group="..."]`)
- Filter and search by group

### Bulk Operations

**Access Bulk Manager:**
- Go to **Dynamic Tags > Bulk Manager**

**Available Operations:**
- ✏️ Inline editing of tag content
- 🗑️ Bulk delete multiple tags
- 📁 Bulk assign to groups
- 🔄 Bulk status changes

### Import/Export Workflows

#### Exporting Tags

1. Go to **Dynamic Tags > Import/Export**
2. Choose export format (JSON, CSV, XML, SQL)
3. Select tags to export:
   - All tags
   - By specific group
   - Selected tags
4. Click **Export** - file downloads automatically

#### Importing Tags

1. Go to **Dynamic Tags > Import/Export**
2. Select import format
3. Upload your file
4. Choose conflict handling:
   - **Skip**: Skip duplicate tags
   - **Update**: Update existing tags
   - **Create New**: Create with modified names
5. Click **Import**

### Database Migration

**Migrating from Post Type to Custom Table:**

1. Go to **Dynamic Tags > Table Manager**
2. View migration status
3. Click **Migrate to Custom Table**
4. Migration runs with progress indicator
5. Automatic verification of migrated data

**Benefits of Custom Table:**
- ⚡ Better performance for large numbers of tags
- 🔍 Advanced query capabilities
- 📊 Better scalability
- 🔄 Maintains compatibility with post type system via bridge

---

## 🎛️ Advanced Features

### Conflict Resolution

**Automatic Conflict Detection:**
The plugin automatically detects:
- Duplicate tag names
- Shortcode conflicts with other plugins
- Group assignment conflicts

**Resolution Strategies:**

1. **Group-Based Organization**
   - Assigns tags to groups to create unique shortcodes
   - Example: `[promo]` becomes `[promo-group-seasonal]`

2. **ID Prefix Strategy**
   - Adds post ID prefix to shortcode
   - Example: `[promo]` becomes `[promo-123]`

3. **Manual Merge**
   - Merge duplicate tags into one
   - Combine content and groups

**Access Conflict Manager:**
- Go to **Dynamic Tags > Conflict Manager**
- View all detected conflicts
- Apply bulk resolution strategies

### Performance Optimization

**Built-in Optimizations:**
- 🚀 Multi-level caching (transients, object cache, static)
- 📉 Query reduction (up to 80% fewer database calls)
- ⚡ Lazy shortcode registration
- 🔄 Smart cache invalidation
- 📊 Performance monitoring dashboard

**Manual Cache Control:**
- Clear cache from admin bar
- Force refresh shortcodes
- Monitor query counts and execution time

### Developer Features

**Available Hooks & Filters:**

```php
// Modify tag content before display
add_filter('dt_process_value', function($content) {
    return strtoupper($content);
});

// Add custom placeholders
add_filter('dt_register_placeholder', function($placeholders) {
    $placeholders['custom_data'] = 'Your custom value';
    return $placeholders;
});

// Modify shortcode output
add_filter('dt_shortcode_output', function($output, $tag_id) {
    return '<div class="dynamic-tag">' . $output . '</div>';
}, 10, 2);
```

**Database Access:**

```php
// Get table manager instance
$table_manager = WP_Dynamic_Tags_Plugin::get_instance()->get_table_manager();

// Create tag programmatically
$result = $table_manager->create_tag(array(
    'tag_key' => 'promo_text',
    'tag_value' => 'Special Offer!',
    'tag_group' => 'marketing',
    'status' => 'active'
));
```

---

## 📚 Shortcode Reference

### Basic Tag Shortcodes

```
[tag_name]                    - Display tag content
[tag_name-group-groupname]    - Display grouped tag
```

### Special Shortcodes

#### dt_fallback
```
[dt_fallback tag="tagname" default="Default text"]
```
**Parameters:**
- `tag` (required) - Tag name to display
- `default` (required) - Fallback text if tag doesn't exist

#### dt_group
```
[dt_group name="groupname" separator=", " before="<div>" after="</div>"]
```
**Parameters:**
- `name` (required) - Group name
- `separator` (optional) - Separator between tags (default: ", ")
- `before` (optional) - HTML before each tag
- `after` (optional) - HTML after each tag

#### dt_random
```
[dt_random group="groupname" cache="yes"]
```
**Parameters:**
- `group` (optional) - Limit to specific group
- `cache` (optional) - Cache random selection (default: "no")

#### dt_count
```
[dt_count group="groupname" format="text"]
```
**Parameters:**
- `group` (optional) - Count tags in specific group
- `format` (optional) - "number" or "text" (default: "number")

#### dt_list
```
[dt_list group="groupname" type="ul" class="custom-class"]
```
**Parameters:**
- `group` (optional) - List tags from group (default: all tags)
- `type` (optional) - "ul" or "ol" (default: "ul")
- `class` (optional) - Custom CSS class for list

---

## 🔍 Troubleshooting

### Common Issues

**Shortcodes Not Working**
- ✅ Ensure plugin is activated
- ✅ Check tag name is correct (case-sensitive)
- ✅ Clear cache (WordPress cache, page builder cache)
- ✅ Go to **Dynamic Tags > All Tags** and click "Refresh Shortcodes"

**Duplicate Tag Names**
- ✅ Use **Conflict Manager** to auto-resolve
- ✅ Assign tags to different groups
- ✅ Use unique tag names

**Import Fails**
- ✅ Check file format matches selected import type
- ✅ Ensure file encoding is UTF-8
- ✅ Verify file structure against sample files
- ✅ Check PHP upload limits (upload_max_filesize, post_max_size)

**Performance Issues**
- ✅ Enable object caching (Redis, Memcached)
- ✅ Clear old transient data
- ✅ Migrate to custom table for better performance
- ✅ Reduce number of dynamic placeholders

**Migration Issues**
- ✅ Backup database before migration
- ✅ Ensure adequate database permissions
- ✅ Check PHP memory limit (256M+ recommended)
- ✅ Monitor migration progress log

---

## 🗂️ Database Structure

### Custom Table Schema

**Table Name:** `wp_dynamic_tags`

```sql
CREATE TABLE wp_dynamic_tags (
    id bigint(20) PRIMARY KEY AUTO_INCREMENT,
    tag_key varchar(255) UNIQUE NOT NULL,
    tag_value longtext,
    tag_group varchar(255),
    status varchar(20) DEFAULT 'active',
    usage_count int(11) DEFAULT 0,
    created_at datetime,
    updated_at datetime,
    INDEX idx_tag_key (tag_key),
    INDEX idx_tag_group (tag_group),
    INDEX idx_status (status)
);
```

**Fields:**
- `id` - Unique identifier
- `tag_key` - Shortcode name (unique)
- `tag_value` - Tag content (supports HTML)
- `tag_group` - Group assignment
- `status` - active/inactive/trash
- `usage_count` - Number of times used
- `created_at` - Creation timestamp
- `updated_at` - Last modified timestamp

---

## 🔄 Changelog

### Version 2.0.0 (Current)
**Major Update - Custom Table Support**

#### New Features:
- ✨ Custom database table with optimized schema
- 🔄 Bi-directional migration (Post Type ↔ Table)
- 🌉 Post-Table bridge for compatibility
- 📊 Table-based shortcode processor
- 📤 Enhanced import/export (XML, SQL support)
- ⚡ Significant performance improvements
- 🛡️ Enhanced conflict resolution system
- 📈 Performance monitoring dashboard

#### Improvements:
- 🚀 Multi-level caching system
- 🔍 Advanced filtering and search
- 📁 Better tag group management
- 🎨 Improved admin UI/UX
- 🐛 Bug fixes and stability improvements
- 📝 Enhanced documentation

#### Breaking Changes:
- Requires PHP 7.4+ (upgraded from 7.2)
- New database table (automatic creation on activation)

### Version 1.x
- Initial release with post type system
- Basic tag management
- Simple import/export
- Group support via taxonomy

---

## 🤝 Support & Contributing

### Getting Help

**Documentation:**
- 📚 Read this README
- 💡 Check the [Wiki](https://github.com/yourusername/wp-dynamic-tags/wiki)
- ❓ See [FAQ](https://github.com/yourusername/wp-dynamic-tags/wiki/FAQ)

**Support Channels:**
- 🐛 [Report Issues](https://github.com/yourusername/wp-dynamic-tags/issues)
- 💬 [Community Forum](https://wordpress.org/support/plugin/wp-dynamic-tags/)
- 📧 Email: support@yourwebsite.com

### Contributing

We welcome contributions! Here's how:

1. 🍴 Fork the repository
2. 🌿 Create a feature branch (`git checkout -b feature/amazing-feature`)
3. 💻 Make your changes
4. ✅ Test thoroughly
5. 📝 Commit (`git commit -m 'Add amazing feature'`)
6. 📤 Push to branch (`git push origin feature/amazing-feature`)
7. 🔄 Open a Pull Request

**Development Setup:**
```bash
git clone https://github.com/yourusername/wp-dynamic-tags.git
cd wp-dynamic-tags
# Install in WordPress plugins directory
```

### Coding Standards
- Follow [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/)
- Comment your code
- Write meaningful commit messages
- Include tests for new features

---

## 📄 License

This plugin is licensed under the GPL v2 or later.

```
WP Dynamic Tags - Create and manage unlimited dynamic tags
Copyright (C) 2025 Eshaan Manchanda

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.
```

Full license: [GPL v2 License](https://www.gnu.org/licenses/gpl-2.0.html)

---

## 👨‍💻 Author

**Eshaan Manchanda**
- Website: [https://yourwebsite.com](https://yourwebsite.com)
- GitHub: [@yourusername](https://github.com/yourusername)
- Plugin URI: [https://github.com/yourusername/wp-dynamic-tags](https://github.com/yourusername/wp-dynamic-tags)

---

## 🙏 Acknowledgments

- Thanks to the WordPress community
- Built with ❤️ for content creators and developers
- Inspired by the need for flexible, reusable content management

---

## 🔗 Useful Links

- [WordPress Plugin Directory](https://wordpress.org/plugins/)
- [WordPress Developer Handbook](https://developer.wordpress.org/)
- [Elementor Documentation](https://elementor.com/help/)
- [Page Builder Documentation](https://wordpress.org/plugins/tags/page-builder/)

---

**⭐ If you find this plugin useful, please consider giving it a star on GitHub!**

---

<div align="center">

**Made with ❤️ for WordPress**

[Report Bug](https://github.com/yourusername/wp-dynamic-tags/issues) · [Request Feature](https://github.com/yourusername/wp-dynamic-tags/issues) · [Documentation](https://github.com/yourusername/wp-dynamic-tags/wiki)

</div>
