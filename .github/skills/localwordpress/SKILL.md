---
name: localwordpress
description: Describe what this skill does and when to use it. Include keywords that help agents identify relevant tasks.
---

<!-- Tip: Use /create-skill in chat to generate content with agent assistance -->

Never use wp-cron.php or wp_schedule_event. 

Only use WP-CLI commands or WooCommerce Action Scheduler for background processing. 

Always use the WP_Http class for external API calls. 

Avoid post meta for logging. 

Strictly use custom database tables.