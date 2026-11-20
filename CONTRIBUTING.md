# Contributing Guidelines & Engineering Standards

Welcome to **CastelRoomKeeper**! This is a room, laboratory, and resource booking engine for educational and institutional environments, featuring ownership locks, approval workflows, SQLite concurrency, and SMTP notifications.

---

## 🏛️ Code of Conduct & Mutual Respect

All contributors, reviewers, and maintainers are required to follow our [Code of Conduct](CODE_OF_CONDUCT.md).
We expect all discussions in issues and pull requests to remain professional, constructive, and technically grounded.
**Insults, demanding entitlement, toxic behavior, or harassment will result in immediate thread locking and account blocking.**

---

## 📋 Issue-First Workflow (Mandatory)

Before opening a Pull Request:
1. **Search existing issues** to avoid duplicate proposals.
2. **Open an Issue first** describing the bug with logs and environment details, or detailing room scheduling features before writing code.
3. **Wait for maintainer approval** before undertaking large refactors or adding dependencies. Unsolicited massive PRs will be closed without review.

---

## 🛡️ Security & Privacy Mandate

* **SQL Injection & Prepared Statements**: All database operations must use PDO prepared statements with parameterized queries. Raw string concatenation in queries is strictly forbidden.
* **Authentication & CSRF**: All state-modifying requests must validate session tokens and CSRF protections.
* **Zero Leak Policy**: Never submit real school database dumps, production passwords, or SMTP secrets.

---

## 🧪 Quality Gates & Testing

* **PHP Linting**: Must pass `php -l` on all PHP files without syntax errors.
* **Zero Warnings**: Code must run without PHP notices or warnings under `E_ALL`.
* See [AGENTS.md](AGENTS.md) for automated contributor rules.
