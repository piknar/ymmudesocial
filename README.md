# YMMUDE Social Login — Joomla 6 System Plugin

![Version](https://img.shields.io/badge/version-1.0.0-purple)
![Joomla](https://img.shields.io/badge/joomla-6.1%2B-blue)
![License](https://img.shields.io/badge/license-GPL--2.0-green)

**Unified social login for Google, Apple, Facebook, and GitHub** — injects styled login buttons on Joomla login, registration, and sidebar module pages. New users are auto-registered without activation emails, and pending accounts are auto-activated when the provider verifies their email.

---

## ✨ Features

| Feature | Detail |
|---|---|
| **4 providers** | Google · Apple · Facebook · GitHub — enable independently |
| **Auto-register** | First-time visitors get a Joomla account instantly — no activation email |
| **Auto-link** | Returning users signed into the same provider are linked and logged in |
| **Auto-activate** | Pending users who never clicked the activation email are activated if the provider confirms the email |
| **Clean URLs** | `/social-login/google`, `/social-login/apple/callback` — SEO-friendly, no query strings |
| **Theme matching** | Buttons are styled with the site's purple/cyan theme (dark & light mode) |
| **Shortcode** | `{ymmude_social_login}` renders buttons in any article or custom module |
| **HMAC-secure** | CSRF-protected state tokens with 15-minute expiry |
| **Admin UI** | One tab per provider in Joomla's plugin manager, with inline callback URL notes |

---

## 📦 Installation

### From GitHub

1. Download the latest release ZIP or clone this repo.
2. In Joomla Admin, go to **System → Install → Extensions → Upload Package File**.
3. Upload `ymmudesocial.zip` (the entire plugin folder zipped).
4. Go to **System → Plugins → "System - YMMUDE Social Login"** and enable it.
5. Open each provider tab and enter your API credentials.

### Manual Install

```bash
# Copy the plugin folder to your Joomla installation
cp -r ymmudesocial /var/www/YOURSITE/plugins/system/
chown -R www-data:www-data /var/www/YOURSITE/plugins/system/ymmudesocial

# SQL: Register the plugin in your Joomla database
INSERT INTO YOURDB.yourprefix_extensions
  (package_id, name, type, element, folder, client_id, enabled, access, protected,
   locked, manifest_cache, params, custom_data, checked_out, checked_out_time, ordering, state)
SELECT 0, 'plg_system_ymmudesocial', 'plugin', 'ymmudesocial', 'system', 0, 1, 1, 0, 0,
       '{}', '{"allow_user_creation":"1","default_group":"2","auto_activate":"1","remember_me":"1"}',
       '', 0, NULL, -10, 0
WHERE NOT EXISTS (SELECT 1 FROM YOURDB.yourprefix_extensions WHERE element='ymmudesocial' AND folder='system');

# Clear caches
rm -rf /var/www/YOURSITE/administrator/cache/* /var/www/YOURSITE/cache/*
systemctl restart apache2
```

> ⚠️ Always pipe SQL containing JSON via **stdin**, never inline `mysql -e` — shell quoting strips JSON double quotes, causing `0 - Error decoding JSON data` site-wide 500 errors.

---

## 🔧 Configuration

Go to **System → Plugins → "System - YMMUDE Social Login"** (extension ID 351 on whymuddy, 311 on ymmude).

### Provider Tabs

| Tab | Fields | Notes |
|---|---|---|
| **Google** | Client ID · Client Secret | OAuth 2.0 Web Application |
| **Apple** | Services ID · Team ID · Key ID · `.p8` private key | Requires Apple Developer account ($99/yr) |
| **Facebook** | App ID · App Secret | Facebook Login product |
| **GitHub** | Client ID · Client Secret | OAuth App |

Each tab displays the exact **callback URL** to use when registering the app with that provider.

### General Settings

| Setting | Default | Description |
|---|---|---|
| **Create new accounts** | Yes | Auto-register visitors with no matching account |
| **Default user group** | Registered (2) | Group assigned to social-created accounts |
| **Auto-activate pending** | Yes | Activate users who never clicked the email link when provider confirms their email |
| **Link by unverified email** | No | Only link to existing accounts when the provider confirmed the email (security) |
| **Remember me** | Yes | Set the Remember Me cookie after social login |
| **Redirect after login** | (empty) | Internal path like `/my-music`; empty = profile page or return-to-page |

### Display Settings

| Setting | Default | Description |
|---|---|---|
| **Heading text** | `or continue with` | Separator above buttons; empty to hide |
| **Button text** | `Continue with %s` | `%s` is replaced with the provider name |
| **Login module selector** | `.mod-login__submit` | CSS selector where buttons are injected in the sidebar login module |
| **Login page selector** | `.com-users-login__submit` | CSS selector on `/login` page |
| **Registration selector** | `.com-users-registration__submit` | CSS selector on `/register` page |
| **Shortcode** | `{ymmude_social_login}` | Place in any article or custom module to render buttons |

---

## 🌐 Callback URLs

Register these in each provider's developer console. Replace `YOURSITE.COM` with your domain:

| Provider | Callback URL |
|---|---|
| **Google** | `https://YOURSITE.COM/social-login/google/callback` |
| **Apple** | `https://YOURSITE.COM/social-login/apple/callback` |
| **Facebook** | `https://YOURSITE.COM/social-login/facebook/callback` |
| **GitHub** | `https://YOURSITE.COM/social-login/github/callback` |

> Apple also requires your domain in **Services ID → Sign in with Apple → Domains**: `YOURSITE.COM` (no `https://`, no path).

---

## 🔑 Getting API Credentials

### Google (free, ~5 min)

1. Go to [Google Cloud Console](https://console.cloud.google.com/) → create a project.
2. **APIs & Services → OAuth consent screen** → External → fill App name, support email → add scopes `email`, `profile`, `openid` → Publish.
3. **Credentials → + Create Credentials → OAuth client ID** → Web application.
4. Add **Authorized redirect URIs**: `https://YOURSITE.COM/social-login/google/callback`
5. Copy **Client ID** (`...apps.googleusercontent.com`) and **Client Secret** (`GOCSPX-...`).

### GitHub (free, ~2 min)

1. Go to [GitHub Developer Settings](https://github.com/settings/developers) → **OAuth Apps → New OAuth App**.
2. **Authorization callback URL**: `https://YOURSITE.COM/social-login/github/callback`
3. Register → copy **Client ID** → **Generate a new client secret**.

### Facebook / Meta (free, ~10 min)

1. Go to [Meta for Developers](https://developers.facebook.com/) → **My Apps → Create App** → **Authenticate and request data from users**.
2. **Add product → Facebook Login → Web** → site URL.
3. **Facebook Login → Settings → Valid OAuth Redirect URIs**: `https://YOURSITE.COM/social-login/facebook/callback`
4. **App settings → Basic** → copy **App ID** and **App Secret**.
5. Switch app from **Development to Live** at the top toggle.

### Apple (requires Apple Developer Program, $99/yr, ~15 min)

1. Go to [Apple Developer](https://developer.apple.com/account/) → **Certificates, Identifiers & Profiles**.
2. Create an **App ID** with Sign in with Apple enabled.
3. Create a **Services ID** (e.g., `com.yourdomain.web`) — this is your **Client ID**.
4. Configure Sign in with Apple on that Services ID: add domain `YOURSITE.COM` and return URL `https://YOURSITE.COM/social-login/apple/callback`.
5. Create a **Key** with Sign in with Apple → download the `.p8` file (only once!). Note the **Key ID**.
6. Your **Team ID** is visible under Membership details.

---

## 🏗 Architecture

| Layer | Technology | Purpose |
|---|---|---|
| **Routing** | `onAfterInitialise` hook | Intercepts `/social-login/{provider}[/callback]` before Joomla routing (404-safe) |
| **State** | HMAC-SHA256 signed tokens | CSRF protection, 15-min expiry, no session dependency |
| **Auth** | OAuth 2.0 / OpenID Connect | Standard flows per provider; Apple uses `form_post` response mode |
| **HTTP** | Joomla `HttpFactory` | All API calls via curl/stream adapters |
| **JWT** | ES256 (Apple only) | Runtime-generated client secret from Team ID + Key ID + `.p8` private key, 30-day expiry |
| **User resolution** | `#__ymmudesocial_links` table | Maps `(provider, provider_uid)` → Joomla `user_id`, fallback to email match |
| **Session** | `PluginHelper::importPlugin('user')` + `LoginEvent` | Mirrors `CMSApplication::login()` pattern — dispatches `onUserLogin` with authentication response + options array |
| **Button injection** | `onBeforeCompileHead` + DOM insertion | CSS + inline script injects themed buttons before configurable CSS selectors |

### Database

```sql
CREATE TABLE IF NOT EXISTS `#__ymmudesocial_links` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT NOT NULL,
    `provider` VARCHAR(20) NOT NULL,
    `provider_uid` VARCHAR(191) NOT NULL,
    `email` VARCHAR(255) NOT NULL DEFAULT '',
    `created` DATETIME NOT NULL,
    `last_login` DATETIME NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_provider_uid` (`provider`, `provider_uid`),
    KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

## 🔒 Security

- **State tokens**: HMAC-SHA256 signed with the Joomla secret key, 15-minute expiry, provider-embedded validation.
- **Email linking**: Only verified emails link to existing accounts by default (`link_unverified=0`).
- **Redirect validation**: Return URLs validated with `Uri::isInternal()`.
- **No passwords**: Generated 24-character random passwords — users never see or type them.

---

## 🖼 Screenshots

Buttons are styled to match the YMMUDE theme (purple `#8b5cf6`, cyan `#06b6d4`, monospace fonts, dark/light mode).

```
┌─────────────────────────────────────┐
│         or continue with            │
│ ┌─────────────────────────────────┐ │
│ │ [G]  Continue with Google       │ │
│ └─────────────────────────────────┘ │
│ ┌─────────────────────────────────┐ │
│ │ []  Continue with Apple        │ │
│ └─────────────────────────────────┘ │
│ ┌─────────────────────────────────┐ │
│ │ [f]  Continue with Facebook     │ │
│ └─────────────────────────────────┘ │
│ ┌─────────────────────────────────┐ │
│ │ [GH] Continue with GitHub       │ │
│ └─────────────────────────────────┘ │
└─────────────────────────────────────┘
```

---

## 🐛 Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| **"Login Failed — The options param must be an array…"** | `HttpFactory::getHttp(null, ...)` — joomla/http v3 rejects null | Ensure `http()` returns `HttpFactory::getHttp([], ['curl', 'stream'])` |
| **Social login did nothing after callback** | User plugins not imported | Ensure `PluginHelper::importPlugin('user', null, true, $dispatcher)` before dispatching `LoginEvent` |
| **Site-wide HTTP 500 "Error decoding JSON data"** | Corrupted `params` JSON (double quotes stripped by shell) | Pipe SQL containing JSON via **stdin**, never inline `mysql -e "..."` |
| **Buttons don't appear** | Provider not enabled or missing credentials | Enable the provider **and** enter Client ID/Secret (both required) |
| **Apple login fails with "invalid_client"** | Client secret JWT expired or key mismatch | Verify Team ID, Key ID, and `.p8` contents in plugin settings |

---

## 🚀 Deployed Sites

| Site | Domain | Status |
|---|---|---|
| **Test / Staging** | [whymuddy.com](https://whymuddy.com) | ✅ Active |
| **Production** | [ymmude.com](https://ymmude.com) | ✅ Active |

---

## 📄 License

**GNU General Public License v2.0 or later**

YMMUDE Social Login — Joomla 6 system plugin
Copyright (C) 2026 ymmude.com

This program is free software; you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation; either version 2 of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU General Public License for more details.

You should have received a copy of the GNU General Public License along with this program; if not, write to the Free Software Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.

See [LICENSE](LICENSE) for the full text.

---

## 🔗 Related

- [ymmude.com](https://ymmude.com) — Production site
- [whymuddy.com](https://whymuddy.com) — Test site
- [joomla.org](https://www.joomla.org/) — Joomla CMS
