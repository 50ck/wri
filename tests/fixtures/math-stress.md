# Prueba de estrés: diagramas y fórmulas matemáticas

Todo el contenido es dummy: variables, matrices, valores y relaciones son ejemplos sintéticos sin datos personales ni mediciones reales.

Este documento prueba matemáticas en línea, bloques LaTeX y diagramas construidos con matrices y flechas. Requiere un visor Markdown con soporte matemático, como KaTeX o MathJax. Los diagramas usan comandos habituales sin extensiones como TikZ o paquetes de diagramas conmutativos.

## 1. Fórmulas en línea

Parámetros ficticios: $\alpha=2$, $\beta=3$, $\gamma=5$. Una función dummy es $f(x)=\alpha x^2+\beta x+\gamma$ y su derivada es $f'(x)=2\alpha x+\beta$.

Símbolos: $a_i$, $b^{(k)}$, $x_{i,j}^{n+1}$, $\hat{x}$, $\bar{y}$, $\vec{v}$, $\mathbb{R}$, $\mathcal{D}$, $\varnothing$, $\forall$, $\exists$ y $\infty$.

## 2. Cadena de transformaciones dummy

$$
\boxed{x=2}
\xrightarrow{\text{sumar }3}
\boxed{u=x+3=5}
\xrightarrow{\text{elevar al cuadrado}}
\boxed{v=u^2=25}
\xrightarrow{\text{dividir entre }5}
\boxed{y=5}
$$

## 3. Diagrama conmutativo sintético

Sean $f(x)=x+1$, $g(x)=2x$, $h(x)=2x$ y $k(x)=x+2$. Entonces $h(f(x))=k(g(x))$.

$$
\begin{array}{ccc}
\mathbb{R} & \xrightarrow{\quad f\quad} & \mathbb{R} \\
{\scriptstyle g}\downarrow & & \downarrow{\scriptstyle h} \\
\mathbb{R} & \xrightarrow{\quad k\quad} & \mathbb{R}
\end{array}
$$

## 4. Árbol de bifurcaciones matemáticas

$$
\begin{array}{ccccc}
& & x & & \\
& \swarrow & & \searrow & \\
x+1 & & & & x-1 \\
\downarrow & & & & \downarrow \\
(x+1)^2 & & & & (x-1)^2 \\
& \searrow & & \swarrow & \\
& & 2x^2+2 & &
\end{array}
$$

Las flechas finales representan la suma de las dos ramas.

## 5. Fracciones y radicales anidados

$$
F(x)=
\frac{
    1+\frac{x^2}{1+\frac{x^4}{1+x^6}}
}{
    \sqrt{1+\sqrt{1+\sqrt{1+x^2}}}
}
\qquad
G(x)=\sqrt[3]{\frac{x^2+1}{\sqrt{x^4+2}}}
$$

## 6. Matriz ancha de valores ficticios

$$
A_{\mathrm{dummy}}=
\begin{pmatrix}
1 & 2 & 3 & 4 & 5 & 6 & 7 & 8 \\
2 & 3 & 4 & 5 & 6 & 7 & 8 & 9 \\
3 & 4 & 5 & 6 & 7 & 8 & 9 & 10 \\
4 & 5 & 6 & 7 & 8 & 9 & 10 & 11 \\
5 & 6 & 7 & 8 & 9 & 10 & 11 & 12 \\
6 & 7 & 8 & 9 & 10 & 11 & 12 & 13 \\
7 & 8 & 9 & 10 & 11 & 12 & 13 & 14 \\
8 & 9 & 10 & 11 & 12 & 13 & 14 & 15
\end{pmatrix}
$$

## 7. Diagrama de transformación matricial

$$
\underbrace{
\begin{pmatrix}1\\2\\3\end{pmatrix}
}_{\text{entrada dummy}}
\xrightarrow{\quad A=\begin{pmatrix}1&0&1\\0&2&0\\1&0&-1\end{pmatrix}\quad}
\underbrace{
\begin{pmatrix}4\\4\\-2\end{pmatrix}
}_{\text{salida dummy}}
$$

## 8. Sistema alineado y función por tramos

$$
\left\{
\begin{aligned}
x+y+z &= 6, \\
2x-y+z &= 3, \\
x+2y-z &= 2.
\end{aligned}
\right.
\qquad
(x,y,z)=(1,2,3)
$$

$$
q(x)=
\begin{cases}
0, & x<0, \\
x^2, & 0\le x<2, \\
4, & x\ge 2.
\end{cases}
$$

## 9. Sumatorios, productos, límites e integrales

$$
\sum_{k=1}^{n}k=\frac{n(n+1)}{2},
\qquad
\prod_{k=1}^{4}k=24,
\qquad
\lim_{n\to\infty}\left(1+\frac{1}{n}\right)^n=e
$$

$$
\int_0^1 x^2\,\mathrm{d}x=\frac{1}{3},
\qquad
\int_0^1\int_0^1(x+y)\,\mathrm{d}x\,\mathrm{d}y=1
$$

## 10. Derivación de varias líneas

$$
\begin{aligned}
E(x)
&=(x+\alpha)^2-(x-\alpha)^2 \\
&=x^2+2\alpha x+\alpha^2
 -\left(x^2-2\alpha x+\alpha^2\right) \\
&=4\alpha x \\
\left.E(x)\right|_{\alpha=2,\,x=3}
&=24.
\end{aligned}
$$

## 11. Anotaciones superiores e inferiores

$$
\overbrace{a_1+a_2+a_3+a_4}^{\text{bloque Alfa}}
+
\underbrace{b_1+b_2+b_3+b_4}_{\text{bloque Beta}}
=
\boxed{S_{\mathrm{dummy}}}
$$

$$
\underbrace{
\overbrace{1+2+3}^{6}
+
\overbrace{4+5+6}^{15}
}_{21}
$$

## 12. Probabilidad sintética

$$
P(X=k)=\binom{n}{k}p^k(1-p)^{n-k},
\qquad n=8,\quad p=\frac{1}{2},\quad k\in\{0,\ldots,8\}
$$

$$
\mathbb{E}[X]=np=4,
\qquad
\operatorname{Var}(X)=np(1-p)=2
$$

## 13. Fórmula larga para probar el ancho

$$
\mathcal{L}_{\mathrm{dummy}}(\theta)=
\underbrace{\frac{1}{N}\sum_{i=1}^{N}\left(y_i-\sum_{j=1}^{M}\theta_j x_{i,j}\right)^2}_{\text{error sintético}}
+\underbrace{\lambda\sum_{j=1}^{M}\lvert\theta_j\rvert}_{\text{penalización Alfa}}
+\underbrace{\mu\sum_{j=1}^{M}\theta_j^2}_{\text{penalización Beta}}
+\underbrace{\nu\sum_{j=1}^{M-1}(\theta_{j+1}-\theta_j)^2}_{\text{suavidad ficticia}}
$$

## 14. Matriz de bloques y delimitadores grandes

$$
B=
\left[
\begin{array}{cc|cc}
1 & 0 & 2 & 3 \\
0 & 1 & 4 & 5 \\
\hline
6 & 7 & 1 & 0 \\
8 & 9 & 0 & 1
\end{array}
\right],
\qquad
\left\lVert
\begin{pmatrix}3\\4\end{pmatrix}
\right\rVert_2=5
$$

## 15. Rejilla de fórmulas y flechas

$$
\begin{array}{ccccc}
x & \xrightarrow{+1} & x+1 & \xrightarrow{\times 2} & 2x+2 \\
\downarrow{\scriptstyle \times 2} & & \downarrow{\scriptstyle \times 2} & & \downarrow{\scriptstyle -2} \\
2x & \xrightarrow{+2} & 2x+2 & \xrightarrow{-2} & 2x \\
\downarrow{\scriptstyle +3} & & \downarrow{\scriptstyle +1} & & \downarrow{\scriptstyle +3} \\
2x+3 & \xrightarrow{\text{identidad}} & 2x+3 & \xrightarrow{\text{identidad}} & 2x+3
\end{array}
$$

## 16. Repetición de bloques para comprobar el flujo vertical

$$
f_1(x)=x+1 \quad\longrightarrow\quad f_1(2)=3
$$

Texto dummy entre bloques: Alfa, Beta, Gamma. Comprobar que no se superpone con las fórmulas.

$$
f_2(x)=x^2+2 \quad\longrightarrow\quad f_2(2)=6
$$

Texto dummy entre bloques: Delta, Épsilon, Zeta. Comprobar el margen vertical.

$$
f_3(x)=\frac{x+3}{x+1} \quad\longrightarrow\quad f_3(2)=\frac{5}{3}
$$

Texto dummy final: $\alpha\to\beta\to\gamma$ dentro de un párrafo con **negrita**, *cursiva* y `código dummy`.

## Criterios de revisión visual

- Las fórmulas en línea mantienen la altura y alineación del párrafo.
- Las fracciones anidadas, radicales y delimitadores no se recortan.
- Las matrices mantienen filas y columnas alineadas.
- Las flechas conservan sus etiquetas y dirección.
- Las fórmulas anchas admiten ajuste o desplazamiento sin perder términos.
- Los textos con tildes dentro de las fórmulas se muestran correctamente.
- Los bloques consecutivos no se solapan y la página sigue respondiendo.
