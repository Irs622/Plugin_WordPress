# Admin guide

For Inpartner website administrators. No technical background needed. Technical setup is in [deployment.md](deployment.md).

## What the assistant does

- Answers visitor questions about Inpartner using **only** the approved pages of inpartnerglobal.com.
- Shows the page(s) it used as clickable sources under each answer.
- When it is not sure, says so and offers a link to the contact page instead of guessing.
- Identifies itself as an AI assistant and shows a short disclaimer ("Not professional or investment advice").
- Does **not** give prices, client names, guarantees or personal investment advice unless they are published on the approved pages. Most of these are not.

## Where to find the settings

WordPress dashboard → **WP Knowledge Chat** → **Settings**. Only administrators can see this page.

| Setting | What it does | Tips |
|---|---|---|
| **Enable Chatbot** | Turns the chat button on or off for the whole site | Untick it to switch the assistant off immediately |
| **Greeting Message** | The first message visitors see when they open the chat | Plain text only, max 500 characters. Write it in the same language as *Default Language* |
| **Default Language** | Language of the buttons and messages (English or Bahasa Indonesia), and the language the assistant answers in | Content on the website stays in its original language; service names are not translated |
| **Contact URL** | Where "Contact the Inpartner team" links go | Use `https://inpartnerglobal.com/contact/`. Only https links on inpartnerglobal.com (or this site) are accepted |

Click **Save Changes**. If a value is not accepted, a red message explains why and the old value is kept. If the site uses a page cache (LiteSpeed Cache), **purge the cache** after saving so visitors see the change.

### The "AI backend" box

- **Configured** means the connection details have been added by the technical team.
- **Test connection** checks that the AI service is running. Expected result: "backend is reachable and its knowledge base is ready".
- If it says **Not configured** or the test fails, contact the technical team. The chat button stays hidden while it is not configured.

## Common tasks

**Switch the assistant off quickly.** Untick *Enable Chatbot*, save, and purge the cache. The rest of the website is not affected.

**Change the greeting.** Edit *Greeting Message*, save, and purge the cache.

**Switch to Bahasa Indonesia.** Set *Default Language* to Bahasa Indonesia, update the greeting to Indonesian, save, and purge the cache. Suggested questions change automatically.

**Show it only on some pages.** This needs a small code snippet from the technical team (see the plugin's readme, "Can I show the widget only on some pages?").

**Update what the assistant knows.** The assistant does **not** read new pages automatically. After publishing or changing service pages, ask the technical team to rebuild the knowledge base ([knowledge-base.md](knowledge-base.md)). Review the text before it goes live.

## What visitors see

| Situation | What the visitor sees |
|---|---|
| Answer found | Answer text plus "Sources" with links to the pages used |
| Not enough information | "I couldn't find enough information… please contact the Inpartner team" plus the contact link |
| Too many questions in a minute | A request to wait a minute (limit: 6 questions per minute per visitor) |
| AI service unavailable | "The assistant is unavailable right now…", a **Try again** button and the contact link |

Conversations are not saved on the website. They disappear when the visitor closes the browser tab.

## Before launch: content to fix on the website

The assistant can only be as accurate as the website. These were found during setup (details in [knowledge-base.md](knowledge-base.md)):

1. Contact page: remove the placeholder phone number **(406) 555-0120** and the sentence "Ready to take control of your financial future?…".
2. The footer and the Contact page show **different phone numbers**. Keep one.
3. The **Cross-Border M&A Advisory** page is empty, so the assistant cannot explain this service yet.
4. Fix typos: "Start a Convertation", "Lear More", "Build for Companies".
