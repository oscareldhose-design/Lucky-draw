# MAP Lucky Draw

A prize wheel for events and stands, in MAP's brand (**MAP — Your way home**). It is modelled on
[RepBud's free trade-show prize wheel](https://repbud.app/tools/prize-wheel). Entrants give their
**Name**, **Phone number** and **Email**, and all three are required to enter the draw.

Everything is in one self-contained file, `index.html`. There is no build step, and it has no
accounts, adverts, tracking or network requests.

## Run it

- **On a laptop or tablet:** download the repository and double-click `index.html`. It works offline,
  straight from disk.
- **On your website, with phone sign-ups:** see below.

## Phone sign-ups on your website (themaap.co.uk)

1. Upload `index.html` and `api.php` into a new folder on your website, e.g. `draw`, so they don't
   replace your homepage. The server needs PHP (no database or anything else).
2. In `api.php`, set `ADMIN_PASSWORD` on its first setting line (this repository keeps a placeholder).
3. QR code for guests: `https://themaap.co.uk/draw/`. They get only the sign-up form.
4. On your laptop open `https://themaap.co.uk/draw/#wheel` and log in with that password. New
   sign-ups appear on the wheel within about 2 seconds; **Download Excel (.xlsx)** includes them.
5. Every phone sign-up is saved on your server straight away, and the server also keeps an Excel
   backup (Name | Phone number | Email) that is updated after each one. Download it from **Settings →
   Phone sign-ups → Download Excel from server** (you must be logged in; it can't be downloaded
   any other way).
6. Sign-ups are stored in the `data` folder that `api.php` creates next to itself. **Settings →
   Phone sign-ups → Clear all sign-ups on the server** (or deleting the folder) wipes them.

The first visit opens a short **How it works** guide. You can reopen it any time with the **?**
button. To try the wheel straight away, press **Load 12 demo entries**. These are fictional people
with `@example.com` addresses and Ofcom drama-range numbers. **Clear demo entries** removes only
those, together with their draws in the winner log. If no real draws are left, the next draw is
Round 1 again. The **Demo entries** button asks first if real people are already on the wheel.

## Using it at an event

**Adding people** (Entries tab)
- **Add an entry:** type a name, phone number and email, and optionally a number of tickets. All
  three details are always required, and the error message says which one is missing or invalid.
- **Bulk add:** paste one person per line as `Name, Phone number, Email`, optionally with a fourth
  column for tickets. Commas and tabs both work, so you can paste straight from Excel or Google
  Sheets.
- **Import CSV:** header rows such as *Name / First name + Last name*, *Phone / Mobile* and
  *Email address* are recognised. Use **CSV template** for a ready-made file.
- **Bulk paste and CSV import** follow the same rule as the forms: every row needs a name, a valid
  phone number and a valid email. Other rows are skipped, and the report says why, for example
  *"Added 40, skipped 3: 2 missing a phone number, 1 invalid email."*
- **Up to 2,000 people:** the wheel holds at most 2,000 entries at once. Extra imports, sign-ups and
  additions are refused with a clear message until someone is removed.
- **Duplicates:** by default, the same phone number or email can't be entered twice. `07…` and
  `+44 7…` count as the same number, and email case is ignored.
- **The list:** search, filter (On wheel / Winners / All), edit, remove (with **Undo**) and
  **Return to wheel**. You can also shuffle, sort A–Z or clear all. Searching for a phone number
  finds it however it was typed (`07…` or `+44 7…`). Entries saved by an older version without a
  phone number or email are marked *Needs details*; use **Edit** to add them.

**Guest sign-up**
- **Guest sign-up** in the header opens a full-screen form for guests in a **new window or tab**, so
  a guest who closes it never closes the wheel. You can also open it directly at
  `index.html#signup`. If the browser blocks the new window, the form opens over the wheel instead.
- Above the form, guests see this notice: *"Please ensure that all details are correct as we will
  message a code to your number to confirm you have given the correct details which is necessary to
  claim your prize on stage."*
- Guests enter their name, phone number and email (all required) and must tick the consent box. The
  marketing opt-in is separate, optional and unticked.
- After a guest enters, a confirmation screen says *"You're entered into the raffle!"* and
  *"Thank you, NAME. You may now close this window."* It stays until the guest closes the window;
  it does not reset itself. A **Close window** button appears when the browser allows the page to
  close itself (the window that **Guest sign-up** opens). There is no way back to a blank form from
  the confirmation; the organiser opens **Guest sign-up** again for the next person. When the form
  was shown over the wheel, the confirmation asks the guest to hand the device back instead.
- Guests can't enter without ticking the **"I agree that MAP may store my details…"** box.
- The guest screen never shows how many people have entered, and operator messages never appear
  over it.
- New entries appear on the wheel within a second, including when the sign-up window is on a second
  screen.
- The small **Exit** button asks for the operator PIN if you have set one in Settings. Otherwise it
  asks you to confirm.

**Spinning**
- Press **SPIN**, tap the wheel, or press **Space**. Set the prize for each round in the box beside
  the SPIN button. Once a winner is confirmed the box is cleared, so a prize never carries into the
  next round by mistake. The box then shows the last prize as a hint. After a no-show the redraw
  keeps the same prize.
- The line under the wheel shows only the round and its prize. It never shows how many people or
  tickets are on the wheel; those counts are only in the operator's Entries panel.
- The wheel ticks as it turns and plays a fanfare when it lands. It then highlights the winning
  segment before the winner card opens.
- People who sign up during a spin join the wheel once that draw is finished.

**Winner actions**
- **Remove from wheel:** the default while *Remove winners after they win* is on.
- **Keep on wheel.**
- **No-show — draw again:** logs the draw as a no-show and removes that person from the wheel. It
  then re-spins for the same round and prize.
- Every draw goes into the **Winners** tab with its round, prize, type and time.
- If the page is reloaded or closed while a winner card is open, the card reopens when the page comes
  back. With *Remove winners after they win* on, the winner is off the wheel from the moment it
  lands, so they can't be drawn again.
- **Clear log** in the Winners tab also restarts the rounds at Round 1.

**Event mode**
- Press **Event mode** or **F** to go full screen and keep the screen awake. Only the logo, title,
  round, prize, wheel and SPIN button are shown, with no entrant counts.
- Leave with **Esc** or **Exit event mode**.

**Exports**
- **Download Excel (.xlsx):** one sheet, **Entrants**, with just **Name, Phone number and Email** for
  everyone who entered (on the wheel, winners and removed; demo entries left out). Phone numbers keep
  their leading 0 or +44. The winner log stays in the app's **Winners** tab.
  This button is in the panel header, the Entries tab and the Winners tab.
- **CSV:** the winner log and the entrant list, as UTF-8 files that are safe to open in Excel.

## Fairness

- The winner is picked **first**, using the browser's cryptographically secure random generator
  (`crypto.getRandomValues`). Rejection sampling removes modulo bias. The wheel animation is then
  worked out to land on that person, and the page checks that the pointer agrees.
- **Tickets** set the weights. Someone with 3 tickets has three times the chance of someone with 1,
  and a segment's size matches its share. The Entries panel shows how many people are on the wheel.
  The audience-facing screens don't show entrant counts.

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
  still works for that session and shows a warning. A sign-up window opened from it still gets the
  current entries, PIN and wording from the wheel window. Export before closing the page.

## Keyboard shortcuts

| Key | Action |
| --- | --- |
| **Space** / **Enter** | Spin (when you're not typing in a field) |
| **F** | Event mode on/off |
| **Esc** | Close a dialog. On the winner card it applies the default action. Also leaves event mode. |
| **← / →** | Move between the Entries, Winners and Settings tabs |
| **Enter** (sign-up form) | Move to the next field, then submit |

## Limits

Opened straight from disk (or hosted without `api.php`), guest sign-up syncs only between windows
of the same browser on the same device. Entries from guests' own phones need `api.php` on your
website (see above).

## Files

- `index.html`: the whole app (HTML, CSS and JavaScript in one file).
- `api.php`: receives guests' phone sign-ups on your website and passes them to the wheel.
- `assets/map-logo.svg`, `assets/map-mark.svg`: the MAP lockup and M mark as vector files, traced
  from the brand artwork.
- `assets/brand/`: the original brand sheet and logo.
