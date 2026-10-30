
# EIIN Information API v3.1

A PHP API that retrieves institute head information and employee records
from the Bangladesh EMIS upstream service.

## Endpoints

- `/` - Combined institute and employee information
- `/api/health` - API health check
- `/api/index.php?eiin=104532` - Main API

## Query Parameters

| Parameter | Description | Default |
|---|---|---|
| eiin | Numeric institute EIIN | Required |
| type | all, head, employees | all |
| page | Zero-based employee page | 0 |
| size | Records per page (1-1000) | 100 |
| maxPages | CSV page limit (1-5) | 1 |
| search | Local search in fetched professional fields | Empty |
| designation | Local designation filter | Empty |
| format | json or csv | json |

## Examples

?eiin=104532
?eiin=104532&type=head
?eiin=104532&type=employees
?eiin=104532&page=1&size=100
?eiin=104532&format=csv&maxPages=3
?eiin=104532&designation=Teacher
?eiin=104532&search=Rifat

## Authentication

Optional. Set `API_KEY` in Vercel Environment Variables.

When enabled, send this header:

X-API-Key: YOUR_API_KEY

## Important Notes

- Page numbering starts from 0.
- Search and designation filters only inspect records fetched by the current request.
- The upstream EMIS service controls the actual available data and pagination.
- CSV export includes only an allowlist of professional fields.
- Do not commit API keys or secrets to GitHub.

## Original Credits

Original API credits: @Al_Nayeem756 / @Cyber_Force756
