# 1000+ Hours of Learning Claude: Beginner to Pro Masterclass (Dan Martell Framework)

## Level 1: The Amateur (Fancy Google Search)

- **Problem:** Treats Claude like a search engine (one question in, response out, close tab). No memory, no persistent context, using only ~5% of its capability.
- **Pro Tips to Level Up:**
  1. **Interviewing Prompt:** Tell Claude: _"Before you answer, ask me any questions that you need to perform this task properly."_
  2. **Self-Correction Check:** Explicitly instruct Claude to review its output by saying: _"Check your work."_

---

## Level 2: The Regular (The Workspace & Projects)

- **Approach:** Utilizes Claude **Projects** for persistent memory, setting them up per initiative, role, or client (e.g., Marketing project folder).
- **Setup Guide:**
  1. Create a new project role directory.
  2. **Master Prompt Creation:** Ask Claude to interview you: _"Interview me to build a master prompt for my role as a [role]."_ This creates an instruction file detailing your working style, team, tools, and background.
  3. **Add Project Files:** Upload your master prompt alongside company data, samples, guidelines, or templates to give Claude context.
- **System Prompts vs. Master Prompts:**
  - _Master Prompt:_ The "ingredients" (context, background, who you are).
  - _System Prompt:_ The "recipe" (instructions, formatting guidelines, workflow process). Have Claude interview you to build a system prompt for specific repeatable workflows.

---

## Level 3: The Integrator (Connecting Tools & Browsers)

- **Approach:** Plugs Claude into external tools where work happens (Gmail, Google Calendar, Google Drive, Slack, Notion) instead of copy-pasting.
- **Key Features:**
  - **Connectors:** Link data systems so Claude can search, audit, and pull information directly.
  - **Visualizations & Artifacts:** Build graphs, mockups, and interactive mini-apps with sliders and buttons inside the chat.
  - **Composer:** Use Composer to edit and refine text like a collaborative document inside chat.
- **Pro Tip (Claude in Chrome):** Paste instructions generated in chat into the Claude Chrome extension to automate browser-based tasks.

---

## Level 4: The Operator (Human-in-the-Loop Automation)

- **Approach:** Transitions from doer to director, setting up systems that run on their own where you only review and approve.
- **Automation Vectors:**
  - **System Prompts:** Standardizing recurring intellectual property and outputs.
  - **Skills:** Saving frequent workflows as custom skills (e.g., `/company-status` for a weekly metrics summary). Rule of thumb: If you do it >3 times a week, make it a skill.
  - **Co-work & Scheduling Tasks:** Running background tasks or automated schedules (e.g., automated daily morning briefs).
- **Pro Tip (Chaining Skills):** Combine modular skills (e.g., a voice/copywriting skill chained into an email-writing skill) to form automated pipelines.

---

## Level 5: The Builder (Shipping Software & Apps)

- **Approach:** Using Claude not just for answers, but to write code, build dashboards, internal tools, and production software. English becomes the new programming language.
- **Building Categories:**
  - _Loops:_ Recurring jobs running on servers interacting with APIs/agents.
  - _Tools:_ Disposable, one-off tools built for specific project needs.
  - _Apps:_ Real production software (built via text/English descriptions).
- **Pro Tips:**
  - **Plan Mode (`/plan`):** Always start with Plan Mode to have Claude outline architecture, ask questions, and secure your approval _before_ writing code, saving token costs.
  - **Claude Code Remote:** Use `/remote-control` in your terminal to link Claude Code to your phone app for remote development/monitoring.

---

## Level 6: The Agent Orchestrator (Autonomous Infrastructure)

- **Approach:** Designing multi-agent loops that run entire departments, processes, or workflows without direct human involvement (Human _on_ the loop).
- **Agent Architecture Framework:**
  - _Main Agent (Orchestrator):_ The central coordinator (e.g., a CEO or Chief of Staff agent like "Kai") that directs other agents.
  - _Specialized Sub-Agents:_ Atomic agents that own specific workflows and report back to the main agent.
  - _Communication Channels:_ Hooking up communication apps (like Telegram) to talk to your agent network.
- **Pro Tip (Critique Agent Pattern):** Implement a critique agent sub-routine where your main agent submits drafts/research to a reviewing agent for iterative quality control before final delivery.
