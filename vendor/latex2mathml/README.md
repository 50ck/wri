PHP LaTeX-to-MathML runtime from snorky22/latex2mathml-php. MIT license.
Upstream commit: 10c2aed0bec21f3e34715ec9d10a7d0e5dae41a3
https://github.com/snorky22/latex2mathml-php/tree/10c2aed0bec21f3e34715ec9d10a7d0e5dae41a3

Local corrections in Converter.php: grouped expressions append to the current matrix cell instead of creating a new column (which dropped final-cell labels); horizontal rules retain their actual row boundaries. Regression coverage is in tests/core.php.
No Composer or JavaScript runtime is needed. OMML code is included as upstream source but is unused by this app.
