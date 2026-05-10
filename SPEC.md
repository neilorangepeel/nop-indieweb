# POSSE/IndieWeb WordPress Plugin Spec

Paste this prompt into Claude Code from the `nop-indieweb` plugin directory to begin the build.

---

## Prompt

You are helping me build a POSSE/IndieWeb WordPress plugin for my personal site (neilorangepeel.com). I have a detailed specification document that outlines the entire architecture and technical approach.

**Before we start coding, I need you to ask clarifying questions about my WordPress setup and preferences so we build this correctly the first time.**

Here's the project spec:

[POSSE/IndieWeb Plugin Specification - see separate document]

### Context About Me:
- I'm a Belfast-based freelance photographer, videographer, and developer
- I manage 26+ WordPress sites
- I'm building a custom FSE (Full Site Editing) block theme
- I use OwnYourSwarm to bridge Foursquare/Swarm checkins to my site
- I want to eventually POSSE to Mastodon and Bluesky with webmention backfeed
- I'm comfortable with PHP, WordPress plugin architecture, and Git
- I prefer explicit, maintainable code over "magical" solutions

### What I Want to Build (MVP):
1. **Micropub endpoint** to receive Swarm checkins from OwnYourSwarm
2. **Post meta registration** for venue data (name, latitude, longitude, foursquare URL)
3. **Block Bindings integration** to display this data in WordPress editor
4. **Microformats markup** baked into my FSE theme templates
5. **Post format detection** to automatically set format based on Micropub properties
6. **Admin filtering** to show "Filter by Post Format" in the Posts list

### Before Coding, Please Ask Me About:

**1. WordPress Environment:**
- What is your WordPress version? (minimum 6.7, tested on 6.9+)
- Is this a single WordPress install or multisite?
- What's your current theme name/path?
- Do you have any existing plugins that might conflict?

**2. Theme Setup:**
- What is your custom FSE theme directory name?
- Do you already have `/templates/` and `/parts/` directories set up?
- Do you have a `theme.json` file with block settings?
- Do you want me to create example templates or just the plugin code (assuming you'll handle theme templates)?

**3. Plugin Configuration:**
- What should the plugin be named? (e.g., `indieweb-posse-plugin`)
- Where should it live? (standard `/wp-content/plugins/` location?)
- Do you want the Micropub endpoint to require a secret token, or should I set it up differently?
- Should the plugin auto-activate required dependencies (like Block Visibility)?

**4. OwnYourSwarm Integration:**
- Do you have OwnYourSwarm already set up and pointing to your current site?
- Will this be a fresh integration with a new Micropub endpoint, or migrating from existing setup?
- Are you capturing additional Swarm data beyond venue name/location? (e.g., checkin photos, friend tags)

**5. Microformats Preferences:**
- Should the plugin include validation/sanitization functions for microformats?
- Do you want a custom admin page to test the Micropub endpoint, or just the REST endpoint?
- Should I add a simple debugging tool to see what OwnYourSwarm is POSTing?

**6. Post Format Strategy:**
- Do you want custom post formats beyond WordPress defaults? (e.g., `workout`, `walk`)
- If custom, should they be registered in the plugin or theme?
- Should there be an admin page to manage which post formats are active?

**7. Block Visibility Setup:**
- Should the plugin recommend/check for Block Visibility plugin, or leave that manual?
- Do you want template part examples that already have Block Visibility conditions baked in?

**8. Git & Version Control:**
- Are you using Git for this plugin?
- Do you want me to create a `.gitignore` and structure suitable for versioning?

**9. File Organization:**
- Do you prefer flat include structure or organized by feature?
- Should utilities be in one file or split by responsibility?
- Any naming conventions you follow?

**10. Future-Proofing:**
- Should I write the code with hooks/filters in place for Phase 2 (POSSE to Mastodon)?
- Do you want inline comments explaining architecture decisions, or minimal comments?
- Should error handling be verbose (helpful debugging) or minimal?

### Once You've Asked These Questions:
1. **Summarize what I told you** so I can verify it's correct
2. **Outline the build plan** with file structure based on my answers
3. **Ask if I'm ready to code** or if I need to clarify anything
4. **Build incrementally:**
   - Plugin skeleton + hooks
   - Post meta registration
   - Micropub endpoint (with test payload examples)
   - Admin filter
   - Template binding examples
   - Validation utilities

### Success Criteria for This Build:
- ✅ Plugin activates without errors
- ✅ Micropub endpoint responds to POST requests
- ✅ OwnYourSwarm can POST to the endpoint and create a post
- ✅ Post meta is stored and visible in WordPress editor
- ✅ Block Bindings can bind to post meta
- ✅ Code is maintainable, documented, and ready for Phase 2

---

## Let's Start

Please ask me the clarifying questions above (or any others you think are important) so we can build this right. Once I answer, we'll have a clear blueprint and can start coding with confidence.

I'm ready when you are!
