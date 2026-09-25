# MAP Lucky Draw

A prize wheel for events and stands, in MAP's brand (**MAP — Your way home**). It is modelled on
[RepBud's free trade-show prize wheel](https://repbud.app/tools/prize-wheel). Entrants give their
**Name**, **Phone number** and **Email**.

Everything is in one self-contained file, `index.html`. There is no build step, and it has no
accounts, adverts, tracking or network requests.

## Run it

- **On a laptop or tablet:** download the repository and double-click `index.html`. It works offline,
  straight from disk.
- **On the web:** in the GitHub repository go to **Settings → Pages → Build and deployment**, choose
  **Deploy from a branch**, then pick this branch and the `/ (root)` folder. The wheel is then at
  `https://<your-account>.github.io/Lucky-draw/`.

The first visit opens a short **How it works** guide. You can reopen it any time with the **?**
button. To try the wheel straight away, press **Load 12 demo entries**. These are fictional people
with `@example.com` addresses and Ofcom drama-range numbers. **Clear demo entries** removes only
those.

## Using it at an event

**Adding people** (Entries tab)
- **Add an entry:** type a name, phone number and email, and optionally a number of tickets. Name,
  phone and email are required by default; you can change this in Settings.
- **Bulk add:** paste one person per line as `Name, Phone number, Email`, optionally with a fourth
  column for tickets. Commas and tabs both work, so you can paste straight from Excel or Google
  Sheets.
- **Import CSV:** header rows such as *Name / First name + Last name*, *Phone / Mobile* and
  *Email address* are recognised. Use **CSV template** for a ready-made file. Bulk imports need only
  a name and report how many rows were added and skipped.
- **Duplicates:** by default, the same phone number or email can't be entered twice. `07…` and
  `+44 7…` count as the same number, and email case is ignored.
- **The list:** search, filter (On wheel / Winners / All), edit, remove (with **Undo**) and
  **Return to wheel**. You can also shuffle, sort A–Z or clear all.

**Guest sign-up**
- **Guest sign-up** in the header opens a full-screen form for guests. You can also open it directly
  at `index.html#signup`.
- Guests must tick the consent box. The marketing opt-in is separate, optional and unticked. After a
  guest enters, the screen shows *"You're in, NAME!"* and resets itself after about 4 seconds.
- **Second window:** open the sign-up in another window or tab of the same browser, for example with
  **Guest sign-up in a new window** at the bottom of the Entries tab. Place it on a second screen.
  New entries appear on the wheel within a second.
- The small **Exit** button asks for the operator PIN if you have set one in Settings. Otherwise it
  asks you to confirm.

**Spinning**
- Press **SPIN**, tap the wheel, or press **Space**. Set the prize for each round in the box beside
  the SPIN button.
- The wheel ticks as it turns and plays a fanfare when it lands. It then highlights the winning
  segment before the winner card opens.
- People who sign up during a spin join the wheel once that draw is finished.

**Winner actions**
- **Remove from wheel:** the default while *Remove winners after they win* is on.
- **Keep on wheel.**
- **No-show — draw again:** logs the draw as a no-show and removes that person from the wheel. It
  then re-spins for the same round and prize.
- Every draw goes into the **Winners** tab with its round, prize, type and time.

**Event mode**
- Press **Event mode** or **F** to go full screen and keep the screen awake. Only the logo, title,
  prize, wheel and SPIN button are shown.
- Leave with **Esc** or **Exit event mode**.

**Exports**
- **Download Excel (.xlsx):** a real workbook with two sheets:
  - **Entrants:** everyone who entered, with contact details, tickets, status, the rounds they won,
    source, consent and marketing opt-in, and times.
  - **Winners:** the winner log.
  This button is in the panel header, the Entries tab and the Winners tab.
- **CSV:** the winner log and the entrant list, as UTF-8 files that are safe to open in Excel.

## Fairness

- The winner is picked **first**, using the browser's cryptographically secure random generator
  (`crypto.getRandomValues`). Rejection sampling removes modulo bias. The wheel animation is then
  worked out to land on that person, and the page checks that the pointer agrees.
- **Tickets** set the weights. Someone with 3 tickets has three times the chance of someone with 1,
  and a segment's size matches its share. The stage shows the totals, for example
  *"300 on the wheel · 600 tickets"*.

## Privacy and data

- Entries, the winner log and settings are stored **only in this browser, on this device**
  (`localStorage`). Nothing is uploaded.
- Clearing the browser's site data deletes them. **Download the Excel file before you clear
  anything.**
- **Privacy mode** is on by default. It masks phone numbers and emails on screen, and the operator
  can reveal them on the winner card.
- Guest sign-ups record consent with a timestamp. The consent and opt-in wording can be edited in
  Settings.
- **Settings → Delete ALL data** wipes everything.
- If the browser blocks storage (for example some private windows or embedded previews), the wheel
  still works for that session and shows a warning. Export before closing the page.

## Keyboard shortcuts

| Key | Action |
| --- | --- |
| **Space** / **Enter** | Spin (when you're not typing in a field) |
| **F** | Event mode on/off |
| **Esc** | Close a dialog. On the winner card it applies the default action. Also leaves event mode. |
| **← / →** | Move between the Entries, Winners and Settings tabs |
| **Enter** (sign-up form) | Move to the next field, then submit |

## Limits

The guest sign-up syncs live only between windows of the **same browser on the same device**. A
plain HTML file can't receive entries from guests' own phones. Live QR-code entry across devices, as
RepBud offers, would need a small backend: a hosted sign-up form plus a database or API that the
wheel reads.

## Files

- `index.html`: the whole app (HTML, CSS and JavaScript in one file).
- `assets/map-logo.svg`, `assets/map-mark.svg`: the MAP lockup and M mark as vector files, traced
  from the brand artwork.
- `assets/brand/`: the original brand sheet and logo.
