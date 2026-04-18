---
name: anti-slop-ui
description: Eliminate AI-generated aesthetic from admin interfaces and plugin UIs. Use when building, redesigning, or polishing any frontend — admin pages, settings panels, dashboards, or user-facing plugin output. Triggers on phrases like "make it look professional", "polish the UI", "it looks generic", "make it production-ready", or any request to improve visual quality. Addresses common LLM pitfalls in UI generation.
---

# Anti-Slop UI

Prevents AI-generated code from looking AI-generated. LLMs converge on the same defaults — this skill breaks that convergence by flagging the tells and enforcing intentional design decisions.

Adapted from [awaken7050dev/anti-slop-ui](https://github.com/awaken7050dev/anti-slop-ui).

## Core Principle

Every visual element must finish this sentence: "This exists because [product/user reason]." If you cannot finish the sentence, delete the element. Gradients, blobs, decorative shapes, and filler sections all fail this test by default.

## The Tells of AI-Generated UI

### Layout & Structure

1. **Symmetric card grids with identical padding** — The classic "3 cards in a row, same height, same radius." FIX: Vary card sizes. Use asymmetric layouts. Break the grid with a full-width element. For data-dense UIs, make cards tighter.

2. **Too much whitespace in data views** — AI defaults to generous padding from consumer app training data. Admin UIs need density. FIX: `padding: 8px 12px` for table cells, not `padding: 16px 24px`.

3. **Stock hero sections with centered text** — "Welcome to [Plugin Name]" + description + CTA. FIX: If you need a header, make it functional. Show status, show data, or remove it.

4. **Desktop-only design** — Looks polished at 1440px, falls apart on mobile. FIX: Test at 375px. Tap every button. Scroll every section.

### Color & Styling

5. **Purple/blue gradient backgrounds** — THE #1 tell. Every AI defaults to indigo-to-purple. FIX: Use solid colors. Match the host application's palette (WordPress admin uses `#1d2327`, `#2271b1`).

6. **Excessive border-radius (everything is a pill)** — AI loves `border-radius: 20px` on everything. FIX: Pick ONE radius and use it consistently. For admin tools: 0-4px. For consumer-facing: 4-8px. Never pill-shape cards.

7. **Decorative color with no meaning** — Random colored elements. FIX: Every color must be semantic. Green = success. Red = danger. Blue = interactive. Amber = warning. Gray = neutral.

8. **Default component library styling unmodified** — Instantly recognizable stock components. FIX: Override colors, spacing, and hover states to match the host application.

### Typography & Copy

9. **Generic font stacks everywhere** — Inter/Roboto/Poppins on everything. FIX: For WordPress admin, inherit the system font stack. Don't fight the host application's typography.

10. **Em dashes everywhere (—)** — THE single most obvious sign AI wrote the copy. FIX: Replace every `—` with a period, comma, or rewritten sentence. Zero em dashes in user-facing text.

11. **Cocky unverified marketing copy** — "Trusted by thousands." "The #1 solution." FIX: Every claim must be verifiable or removed. Replace superlatives with specifics.

12. **Display font on EVERY heading** — Fancy fonts at 14px look broken. FIX: Display fonts on h1/h2 only. Everything smaller uses the body font.

13. **Floating pill badges with colored dots** — `● PRICING`, `✨ FEATURES`, `🚀 NEW`. FIX: Delete them. The heading is enough.

14. **Eccentric Unicode glyphs as section markers** — §, ¶, ※, ◆ for fake institutional credibility. FIX: Use plain numbers or words.

15. **Decorative dashes before uppercase labels** — `— OVERVIEW`, `|| FEATURES`. FIX: Delete the decoration. Letter-spacing and case already signal "this is a label."

### Animation & Interaction

16. **Animations that serve no purpose** — Bounce effects, elastic springs, parallax. FIX: Hover states only (75-120ms) for admin tools. Remove animation if removing it changes nothing about comprehension.

17. **No loading states (or generic spinners)** — FIX: Every data view needs a skeleton or placeholder that matches the final layout. Never a generic spinner.

18. **No animation on state changes** — Toggle/tab switches just swap text silently. FIX: At minimum, fade content with a 150ms transition so users know something changed.

19. **Header that hides/reveals on scroll** — Jarring "now you see me" effect. FIX: Keep navigation permanently visible or use WordPress's standard admin layout.

### Images & Assets

20. **Placeholder logos (letter in a rounded square)** — The "M" in a box. FIX: Use plain text wordmark or ask for the real logo. Never generate a placeholder icon.

21. **Missing images deployed to production** — `<img src="/hero.jpg">` with no fallback. FIX: Every image needs the actual file, a background-color fallback, and `loading="lazy"` below the fold.

22. **Random accent styling on wordmark letters** — Italicizing one letter for "visual interest." FIX: Uniform styling on wordmarks.

### Spacing & Consistency

23. **Inconsistent spacing** — The #1 subconscious trust-killer. Users can't articulate it but they FEEL it. FIX: Use a 4px spacing scale (4, 8, 12, 16, 24, 32, 48). Every margin and padding must be on the scale.

24. **No data attribution or timestamps** — For data-showing interfaces, missing "Updated: [date]" signals amateur hour. FIX: Show data source and freshness.

### WordPress-Specific Tells

25. **Fighting the WordPress admin style** — Custom CSS that clashes with wp-admin. FIX: Use WordPress admin classes (`widefat`, `button`, `button-primary`, `notice`, `postbox`). Inherit, don't override.

26. **Reinventing WordPress UI patterns** — Custom modals, custom notices, custom tables when WordPress provides them. FIX: Use `WP_List_Table` for data tables, `admin_notices` for messages, WordPress settings API for forms.

27. **Ignoring WordPress color scheme** — Hardcoded colors that break when users change their admin color scheme. FIX: Use WordPress admin CSS variables and classes.

## Anti-Slop Checklist

Run before declaring any UI work done:

- [ ] Every visual element passes the Conceptual Grounding Test
- [ ] Zero em dashes in user-facing text
- [ ] Zero purple/blue gradients
- [ ] Zero `rounded-full` on containers
- [ ] Zero `href="#"` dead links
- [ ] Zero "Welcome to" hero copy
- [ ] Zero placeholder images or broken image URLs
- [ ] All colors from CSS variables / design tokens
- [ ] All spacing on the 4px grid
- [ ] Loading states for every data view
- [ ] Tested at 375px mobile width
- [ ] Inherits host application styling (WordPress admin classes)
- [ ] `prefers-reduced-motion` respected on all animations

## The 3-Second Test

Open the interface fresh. Look for exactly 3 seconds, then look away. If you remember "it was dark with blue accents" or "there were gradient cards" — it's AI-generated. If you remember the CONTENT or the DATA — you've succeeded.
