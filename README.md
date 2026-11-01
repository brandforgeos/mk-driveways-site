# Dimensure report template questionnaire

Static page served at https://buildbro.app/dimensure-templates/ (HostGator, deployed by `.cpanel.yml`).
Answers save to the dimensure-portal Supabase project through the `template_survey_get` / `template_survey_save`
functions, keyed by each person's private link code (`?k=`). No portal data is touched.
