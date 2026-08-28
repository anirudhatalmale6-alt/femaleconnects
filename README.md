# Female Connects

A members site for women to find and message each other as friends. Built with
plain HTML, CSS, JavaScript, PHP and MySQL — no frameworks, no Composer, no build
step, nothing loaded from anyone else's server. It uploads to ordinary shared
hosting and runs.

---

## What is in it

**For members**

| | |
|---|---|
| Sign up | Name, email, password, date of birth, town, interests, bio and a photo |
| Log in | Password hashing, session protection, lockout after repeated wrong passwords |
| Members directory | Search by name, interest or town, filter by town, sort by recently active |
| Profiles | Photo, age, town, bio, interest tags, online/last-seen |
| Private chat | One to one only. New messages appear on their own, without reloading the page |
| Read receipts | Sent / Read under each message you send |
| Unread badge | Live count in the header on every page |
| Block | Instant, permanent, and the other person is never told |
| Report | Six reasons plus a free-text note, goes straight to the admin |
| Edit profile | Change details, swap or remove your photo, change your password |

**For the admin**

| | |
|---|---|
| Dashboard | Members, online now, conversations, messages today, open reports, pending approvals |
| Members | Every account, searchable and filterable; approve, suspend, reinstate or delete |
| Conversations | Every conversation on the site, and the full transcript of any of them |
| Reports | Queue of member reports; suspend, mark actioned, or dismiss |
| Activity log | Audit trail of every admin action, plus recent failed sign-ins |

The admin is a normal login with `role = admin`. Members never see the admin
area, and each member only ever sees her own conversations.

---

## Installing it

1. Upload the **contents of `public_html/`** to your web root (`public_html`,
   `httpdocs` or `www` depending on the host). Upload the `sql` folder one level
   above the web root, or anywhere you can reach it.

2. Create an empty MySQL database and a user with full rights on it (cPanel →
   MySQL Databases).

3. Edit `includes/config.php` and fill in:

   ```php
   'DB_HOST' => 'localhost',
   'DB_NAME' => 'your_database',
   'DB_USER' => 'your_db_user',
   'DB_PASS' => 'your_db_password',
   ```

   While you are in there you can also set `SITE_NAME`, `MIN_AGE`, and whether
   new sign-ups are live straight away or wait for you to approve them
   (`SIGNUP_STATUS` — `'active'` or `'pending'`).

4. Make sure `uploads/avatars/` is writable (chmod 755, or 775 on some hosts).

5. Visit **`https://yourdomain.com/install.php`**. It checks the server, creates
   all the tables and asks you to set up your admin account.

6. **Delete `install.php`.** It refuses to run a second time, but delete it anyway.

7. Log in at `/login.php` with the admin details you just set.

No Composer, no npm, no cron jobs, nothing to keep running in the background.

---

## Requirements

- PHP 7.4 or newer (tested on 8.3) with `pdo_mysql`, `gd` and `mbstring`
- MySQL 5.7+ / MariaDB 10.2+
- Apache with `.htaccess` support, or nginx with the equivalent rules (below)

---

## How the chat works

Shared hosting will not hold a WebSocket open, so the browser asks the server
for anything new every 2.5 seconds:

```
GET api/messages.php?c=12&after=340
```

It only ever asks for messages **after** the last id it already has, so a quiet
poll answers with an empty list and costs almost nothing. The interval is
`CHAT_POLL_MS` in `includes/config.php` if you want it faster or slower.

Polls stop while the tab is in the background and catch up the moment you come
back to it, and the PHP session is closed early on each poll so a slow request
can never block another tab.

---

## Security notes

- Every query is a prepared statement — no SQL can be injected through a form.
- Every dynamic value is escaped on output, and message bodies are inserted with
  `textContent` in JavaScript, so a message containing `<script>` is shown as
  text and never runs.
- Every form that changes something carries a CSRF token.
- Passwords are stored with `password_hash()` (bcrypt) and re-hashed on login if
  PHP's default cost changes.
- Six wrong passwords locks that email out for 15 minutes.
- The session id is regenerated on login, and cookies are HttpOnly, SameSite=Lax
  and Secure whenever the site is on HTTPS.
- Uploaded photos are re-encoded through GD, which strips EXIF and guarantees the
  saved file really is an image. `uploads/.htaccess` also stops PHP running there.
- `includes/.htaccess` blocks direct web access to the code and your database
  password.
- Conversation access is checked on the server for every page **and** every API
  call, so nobody can read a chat by guessing a URL.

### nginx equivalent

If you are on nginx rather than Apache, add this to your server block instead of
relying on the `.htaccess` files:

```nginx
location ^~ /includes/ { deny all; }
location ^~ /uploads/  { location ~ \.php$ { deny all; } }
```

---

## Layout of the files

```
public_html/
  index.php            landing page
  register.php         sign up
  login.php  logout.php
  members.php          the directory
  profile.php          your profile, and other members' profiles
  chat.php             inbox and open conversation
  report.php           report a member
  guidelines.php       community rules
  install.php          one-time setup, delete after use
  api/
    messages.php       poll for new messages
    send.php           post a message
    unread.php         header badge count
  admin/
    index.php          dashboard
    users.php  user.php
    conversations.php  conversation.php
    reports.php
    activity.php
  includes/
    config.php         all settings live here
    db.php             PDO connection and query helpers
    auth.php           accounts, login, uploads
    chat.php           conversations and messages
    helpers.php        escaping, CSRF, dates, avatars
    layout.php         shared page header and footer
  assets/
    css/style.css
    js/chat.js
  uploads/avatars/     member photos
sql/
  schema.sql           the database
```

---

## Configuration you may want to change

All in `includes/config.php`:

| Setting | Default | What it does |
|---|---|---|
| `SITE_NAME` | Female Connects | Shown in the header, title and emails |
| `SITE_TAGLINE` | … | Footer line |
| `MIN_AGE` | 16 | Minimum age at sign-up. `0` switches the check off |
| `SIGNUP_STATUS` | `active` | `pending` makes every new member wait for your approval |
| `MESSAGE_MAX_CHARS` | 2000 | Longest single message |
| `CHAT_POLL_MS` | 2500 | How often the browser checks for new messages |
| `ONLINE_WINDOW_MIN` | 5 | Minutes of inactivity before "online now" turns off |
| `LOGIN_MAX_ATTEMPTS` | 6 | Wrong passwords before the lockout |
| `LOGIN_WINDOW_MIN` | 15 | How long the lockout lasts |
| `AVATAR_MAX_BYTES` | 3 MB | Largest photo accepted |
