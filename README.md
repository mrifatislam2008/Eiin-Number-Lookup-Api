
# EIIN Information API

Version: 3.2.0

A PHP API that retrieves institute head information and employee records
from the Bangladesh EMIS service.

## Project Structure

- api/index.php
- api/health.php
- lib/EiinApi.php
- vercel.json

## Main Endpoint

GET /

Required:
- eiin

Optional:
- type=all|head|employees
- page=0
- size=100
- maxPages=1
- search=
- designation=
- format=json|csv

## Examples

?eiin=104532

?eiin=104532&type=head

?eiin=104532&type=employees

?eiin=104532&page=1&size=100

?eiin=104532&format=csv&maxPages=3

?eiin=104532&designation=Teacher

?eiin=104532&search=Rifat

## Health Check

/api/health

## API Key

Optional Vercel environment variable:

API_KEY

When configured, send:

X-API-Key: YOUR_SECRET_KEY

## Important

- Page numbering starts at 0.
- JSON mode fetches one page.
- CSV mode can fetch up to 5 pages.
- Search and designation filters apply only to fetched records.
- CSV contains an allowlist of professional fields only.
- Upstream EMIS response availability and structure may change.

## Original Credits

@Al_Nayeem756
@Cyber_Force756
