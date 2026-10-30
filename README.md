
# EIIN Information API

A PHP API for retrieving institute head information
and employee records from Bangladesh EMIS.

## Features

- EIIN lookup
- Head information
- Employee list
- Pagination
- Search
- Designation filtering
- JSON response
- CSV export
- Health check
- Optional API key
- Private contact fields excluded

## Endpoints

### Complete information

GET /api/index.php?eiin=120617

### Head information only

GET /api/index.php?eiin=120617&type=head

### Employee list only

GET /api/index.php?eiin=120617&type=employees

### Pagination

GET /api/index.php?eiin=120617&page=0&size=100&maxPages=3

### Search

GET /api/index.php?eiin=120617&search=headmaster

### Designation filter

GET /api/index.php?eiin=120617&designation=teacher

### CSV export

GET /api/index.php?eiin=120617&format=csv

### Health check

GET /api/health

## Optional API Key

Set API_KEY in Vercel Project Settings > Environment
Variables.

Send it using the X-API-Key request header.

If API_KEY is not configured, authentication is disabled.

## Deployment

1. Upload the complete project to GitHub.
2. Connect the repository to Vercel.
3. Set the project root to the repository root.
4. Deploy.

## Important

The employee list depends on the upstream EMIS API.
An empty upstream result will remain an empty employee list.
This API does not create or fabricate employee records.
