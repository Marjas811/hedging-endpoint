# PrestaShop Hedging Endpoint

Custom PHP endpoint for PrestaShop that exposes order data for automated hedging system integration.

## 🎯 Overview

This endpoint serves as a bridge between PrestaShop e-commerce platform and the backend hedging system. It provides a secure REST API for fetching recent orders containing precious metals (gold, silver) with their weights and metal rates.

## 🔧 Tech Stack

- **Language:** PHP (OOP)
- **Platform:** PrestaShop
- **API:** REST (JSON responses)
- **Security:** Bearer token authentication
- **Integration:** Backend hedging system (Node.js/TypeScript)

## 📋 Features

### Order Data Exposure
- Exposes recent orders as JSON
- Includes order details: ID, date, customer info
- Provides product data: weights, metal types, rates

### Filtering & Processing
- Filters orders by metal type (gold/silver)
- Filters by rate source (LBMA/fixing)
- Excludes irrelevant products
- Handles edge cases (missing data, invalid formats)

### Security
- Bearer token authentication
- Token validation on each request
- Secure credential storage
- Access logging for diagnostics

### Diagnostics & Logging
- Logs all API requests
- Tracks authentication attempts
- Records data processing steps
- Helps troubleshoot integration issues

## 🚀 API Endpoints

### `GET /hedging-orders`

Returns recent orders with precious metals.

**Authentication:**
```
Authorization: Bearer {token}
```

**Response:**
```json
{
  "orders": [
    {
      "id": "12345",
      "date": "2025-01-22",
      "customer": "...",
      "products": [
        {
          "name": "Gold Coin",
          "weight": 31.1,
          "metal": "gold",
          "rate": 2150.50,
          "rate_source": "LBMA"
        }
      ]
    }
  ]
}
```

## 💼 Business Value

- **Integration:** Enables automated hedging without manual data export
- **Real-time:** Provides up-to-date order data for risk management
- **Security:** Protects sensitive order information
- **Reliability:** Handles errors gracefully, logs issues for debugging

## 🔐 Security Features

- Bearer token authentication
- Request validation
- Secure data filtering
- Access logging
- No sensitive data in logs

## 🛠️ Implementation Details

### PrestaShop Integration
- Uses PrestaShop ORM for database access
- Follows PrestaShop coding standards
- Compatible with PrestaShop module system

### Data Processing
- Parses order and product data
- Extracts metal weights and rates
- Validates data integrity
- Formats response as JSON

### Error Handling
- Graceful failure on missing data
- Logs errors for diagnostics
- Returns appropriate HTTP status codes

## 📊 Data Flow

```
Backend Hedging System → Bearer Token → PHP Endpoint → PrestaShop DB
                                                      ↓
                                                   JSON Response
```

## 🔗 Related Projects

- [Backend-hedging](https://github.com/Marjas811/Backend-hedging) - Node.js/TypeScript system that consumes this endpoint

---

**Note:** This endpoint is part of a production e-commerce system. Some implementation details are omitted for security reasons.
