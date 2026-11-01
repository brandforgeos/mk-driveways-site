# Dimensure report template questionnaire

Static page served at https://buildbro.app/dimensure-templates/ (HostGator, deployed by `.cpanel.yml`).
Answers save to the dimensure-portal Supabase project through the `template_survey_get` / `template_survey_save`
functions, keyed by each person's private link code (`?k=`). No portal data is touched.

Report builder (keep / remove each section per report type) at https://buildbro.app/dimensure-report-builder/
— same link codes, saved through `template_builder_get` / `template_builder_save`.
