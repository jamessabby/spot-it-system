# Claude Code Masterclass: 50 Tips & Best Practices (Meta Staff Engineer Workflow)

## 1. Foundations & Getting Started
- **Root Directory Execution (Tip #1):** Always launch Claude Code (`claude`) in the root directory of your project. This ensures proper context initialization and configuration loading.
- **Initial Codebase Analysis:** Run `/init` on new projects to analyze the architecture and generate the initial `CLAUDE.md` rules file.
- **`CLAUDE.md` Architecture (Tip #2):** 
  - Runs on a hierarchy (checked-in project memory vs. user global memory at `~/.claude/CLAUDE.md`).
  - Keep files concise (~300 lines optimal) to avoid bloating the initial context window.
  - Include high-level technical architecture, domain context, design patterns, and core requirements.
  - **Validation Loops:** Explicitly define build, compilation, and test loops inside `CLAUDE.md` so the AI can self-correct and iterate until tests pass.

---

## 2. Essential Keyboard Shortcuts & Navigation
- `Shift + Tab`: Toggle between modes (e.g., Accept Edits vs. Plan Mode).
- **Plan Mode:** Heavily recommended for starting features to verify assumptions and direction before executing edits.
- `Escape`: Interrupts Claude's current thought or execution loop. Safe to use frequently to course-correct.
- **Double Tap Escape (Empty Input):** Rewinds to a previous point in the conversation and restores context.
- **Drag & Drop Screenshots:** Essential for UI/UX workflows; drag images directly into the terminal or pair with Figma MCPs.

---

## 3. Key Slash Commands
- `/clear`: Clears the current context window to start a fresh task and prevent context pollution.
- `/context`: Visualizes the active context window, token usage, and loaded MCPs to audit bloat.
- `/compact`: Manually summarizes/compacts the current session (though auto-compaction occurs during long sessions).
- `/models`: Switch between models (e.g., default to Claude Opus for complex logic, or Sonnet/Haiku for cost-sensitive tasks).
- `/resume`: Recovers previous session context if an instance was accidentally closed.
- `/mcp`: Displays installed Model Context Protocols (use sparingly to avoid token bloat).
- `/help`: Displays the built-in command wizard and descriptions.

---

## 4. Git as a Safety Net
- Leverage Git workflows, test plans, and commit summaries as a reliable safety net rather than relying solely on session rewinds.

---

## 5. Advanced Configuration & Rules (`CLAUDE.md`)
- **Top-to-Bottom Priority:** Claude reads `CLAUDE.md` from top to bottom, prioritizing top instructions.
- **Custom DSL & Archaic Patterns:** Document homegrown domain-specific languages or unique company patterns in `CLAUDE.md` to prevent AI regressions.
- **Compound Engineering:** Commit refined `CLAUDE.md` files into the repository so teammates benefit from optimized AI workflows.
- **YOLO Mode (`--dangerously-skip-permissions`):** Skips confirmation prompts, but use with extreme caution to prevent destructive OS/file mutations. Use `/permissions` to whitelist safe commands.

---

## 6. Workflows & Context Engineering
- **Fresh & Condensed Context:** Start with Plan Mode, build precise specs, and avoid endless trial-and-error loops that degrade context quality.
- **Second Brain Pattern:** Store persistent todos, project memories, and domain context locally, lazy-loading them only when needed.
- **Validation Loops:** Implement automated testing, debug logging, or UI navigation (e.g., Puppeteer/Chrome MCPs) to give Claude a closed-loop feedback mechanism.

---

## 7. Power User Frameworks: Composing Primitives
- **Skills:** Reusable multi-step workflows (e.g., fetching data from external sources and saving summaries) saved as markdown system prompts.
- **Slash Commands & Skills Integration:** Skills automatically expose corresponding slash commands for easy re-execution.
- **MCPs (Model Context Protocols):** Use targeted MCPs (like Xcode or Figma) to bridge external tools, but audit them carefully to prevent token explosion.
- **Sub-Agents:** Best used for atomic, isolated side-effects rather than complex multi-agent simulations, keeping core context centralized.

---

## 8. Advanced Multi-Instance Scale & Automation
- **Parallel Development:** Run multiple Claude Code instances simultaneously using terminal multiplexers (like iTerm2 split panes/tabs) to act like a multi-threaded engineer.
- **Git Worktrees:** Clone multiple working trees of the same repository to run parallel local code modifications without file lock conflicts.
- **`/chrome` Command:** Automates browser navigation, web scraping, and form filling when direct API access is unavailable.
- **Hooks & Automation:** Configure pre- and post-tool-use hooks for automated linting, formatting, and safety guardrails.
- **Plugin Ecosystem:** Combine skills, MCPs, and bash scripts into shareable plugins for community workflows.
