# EIIN EMIS API — Vercel

এই প্রজেক্টটি দেওয়া EIIN PHP API-এর জন্য Vercel deployment structure।

## Project structure

```text
eiin-emis-vercel-api/
├── api/
│   └── index.php
├── vercel.json
└── README.md
```

## Deploy with GitHub + Vercel

1. GitHub-এ একটি নতুন repository তৈরি করুন, যেমন `eiin-emis-vercel-api`।
2. এই ZIP extract করে এর ভেতরের `api` folder, `vercel.json`, এবং `README.md` repository root-এ upload করুন।
3. Vercel-এ যান: https://vercel.com/new
4. **Import Git Repository** থেকে repository নির্বাচন করুন।
5. Framework preset প্রয়োজন হলে **Other** নির্বাচন করুন। Build command/output directory নিজে থেকে যোগ করবেন না; PHP runtime `vercel.json`-এ নির্ধারিত।
6. Deploy চাপুন।
7. Deploy সফল হলে endpoint পরীক্ষা করুন:

   `https://YOUR-PROJECT.vercel.app/api/?eiin=104532`

`YOUR-PROJECT`-এর জায়গায় Vercel-এর দেওয়া project domain বসাতে হবে।

## Notes

- PHP endpoint: `api/index.php`
- Runtime: `vercel-php@0.9.0` (community PHP runtime)।
- API upstream EMIS service-এর availability ও access policy-এর ওপর নির্ভরশীল।
- TLS certificate verification enabled রাখা হয়েছে।
- Upstream API non-2xx status দিলে API `502` JSON error দেয়; upstream raw response body প্রকাশ করে না।
- API employee names/photos সহ ব্যক্তিগত তথ্য ফেরত দিতে পারে। অনুমোদিত ও প্রয়োজনীয় ব্যবহারের জন্যই ব্যবহার করুন; অপ্রয়োজনীয়ভাবে তথ্য সংরক্ষণ বা পুনঃপ্রকাশ করবেন না.
