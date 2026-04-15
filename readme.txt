KO – AI DRAFT GENERATOR
VERSION 1.3.2.3
PRODUCTION BENCHMARK

************************************************************
OVERVIEW
************************************************************

KO – AI Draft Generator is an internal, role-based content generation tool.

This plugin adds a SIDEBAR META BOX to selected post types (Posts, Pages, Articles, or any CPT you enable).

It uses the OpenAI Responses API to generate initial ideation drafts and allows authorized users to:
- Generate draft content
- Add the generated draft to the post content (server-side save)

This plugin DOES NOT interact with TinyMCE.
This plugin DOES NOT require Visual Editor access.
All content updates are handled via wp_update_post().

************************************************************
KEY FEATURES
************************************************************

- Sidebar Meta Box (no modal window)
- Role-Based Access Control (selectable in settings)
- Post Type Selection (enable Posts, Pages, or any CPT)
- Settings screen hidden from admin nav
  (accessible ONLY via Plugins screen “Settings” link)
- Preview shows formatted output (not raw HTML)
- Add to Content saves server-side (no editor access needed)
- No frontend functionality

************************************************************
INSTALLATION
************************************************************

1. Upload folder to:
   /wp-content/plugins/ko-ai-draft-generator/

2. Activate in:
   WordPress Admin → Plugins

3. Navigate to:
   Plugins → KO – AI Draft Generator → Settings

4. Enter OpenAI API Key.

5. Select the user roles allowed to use the generator.

6. Select which post types should display the AI box.

7. Click Save Settings.

NOTE:
If no roles are selected, the AI box will be disabled for all users.

************************************************************
USAGE
************************************************************

1. Edit an enabled post type (Post/Page/CPT).

2. Use the “KO – AI Draft Generator” box in the sidebar:
   - Enter Topic / Prompt
   - Select Tone / Length
   - Optional keywords
   - Optional: Use current draft as context

3. Click Generate.

4. Review the formatted Preview.
   NOTE: You can copy/paste the preview text into the content area if you prefer.

5. Click Add to Content to write the generated draft into the post content.

************************************************************
ROLE ACCESS CONTROL
************************************************************

Access is controlled via:
Plugins → KO – AI Draft Generator → Settings

Select one or more roles.

If no roles are selected:
The generator is disabled for all users.

Administrators always retain access to the Settings screen.
Non-admin access to settings returns a 404.

************************************************************
CHANGELOG
************************************************************

1.3.2.3 (PRODUCTION BENCHMARK)
- Generate button width limited to match Add to Content button
- Preview constrained to stay inside the metabox
- Added NOTE under Add to Content button
- Fixed JS syntax error that could break Generate/Save
- Improved AJAX error messages (better debugging)

************************************************************
INTERNAL NOTES
************************************************************

This is an INTERNAL PRODUCTION TOOL.

Intended for controlled editorial workflows.
Not intended for public-facing AI access.

************************************************************

