# CRM Campaign Center

This Laravel-based service acts as a **centralized campaign scheduler and quota manager** for multiple CRM applications across hotel units. Each unit may run on its own local infrastructure, but shares one or more Pepipost accounts for email delivery. This system coordinates those schedules and enforces daily limits.

---

## 🎯 Features

- Centralized API to receive and respond to campaign scheduling requests
- Account-aware quota calculation and date suggestion
- API authentication using Bearer tokens
- Endpoint for viewing daily quota usage by date and by Pepipost account
- Designed for distributed CRM units accessing via public API

---

## 📡 API Endpoints (Examples)

| Method | Endpoint                          | Description                             |
|--------|-----------------------------------|-----------------------------------------|
| `GET`  | `/api/schedule/overview`         | View daily schedule for an account group |
| `POST` | `/api/schedule/request` (coming) | Request campaign slot for a future date |

---

## 🔐 Authentication

All API access requires a valid Bearer token in the request headers

