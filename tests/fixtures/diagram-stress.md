# Prueba de estrés: diagramas Markdown

Todo el contenido es ficticio. Los nombres, valores, fechas y relaciones son datos dummy.

Este documento prueba bloques Mermaid, diagramas de texto, etiquetas largas, Unicode, ciclos, relaciones y orientación. Requiere un visor Markdown con soporte Mermaid para los bloques gráficos. Algunos tipos dependen de la versión del visor.

## 1. Flujo con decisiones, ciclos y etiquetas largas

```mermaid
flowchart TD
    A([Inicio ficticio]) --> B[Generar paquete dummy]
    B --> C{¿Valor sintético mayor que 42?}
    C -->|Sí| D[Procesar en módulo Alfa]
    C -->|No| E[Procesar en módulo Beta]
    D --> F{¿Simulación completada?}
    E --> F
    F -->|Reintentar| B
    F -->|Continuar| G[Etiqueta deliberadamente larga para comprobar el ajuste de texto y el tamaño del nodo]
    G --> H([Fin ficticio])
    B -.-> I[Observador: ñ, á, Ω, 漢字, 🚀]
    I -.-> H
```

## 2. Arquitectura con subgrafos

```mermaid
flowchart LR
    subgraph Entrada[Entrada ficticia]
        A[Cliente Uno]
        B[Cliente Dos]
        C[Generador de eventos]
    end
    subgraph Proceso[Procesamiento dummy]
        D[Puerta de entrada]
        E[Cola sintética]
        F[Trabajador Alfa]
        G[Trabajador Beta]
        H{Clasificador}
    end
    subgraph Salida[Resultados simulados]
        I[(Almacén temporal)]
        J[(Archivo ficticio)]
        K[Panel dummy]
    end
    A --> D
    B --> D
    C --> E
    D --> E
    E --> F
    E --> G
    F --> H
    G --> H
    H --> I
    H --> J
    I --> K
    J --> K
    K -.-> D
```

## 3. Secuencia con bucle y alternativas

```mermaid
sequenceDiagram
    autonumber
    actor U as Usuario ficticio
    participant P as Portal dummy
    participant C as Cola sintética
    participant M as Motor de ejemplo
    participant A as Archivo temporal
    U->>P: Solicitar simulación DEMO-001
    P->>C: Encolar paquete ficticio
    C-->>P: Identificador SIM-042
    loop Tres intentos sintéticos
        C->>M: Procesar lote
        activate M
        alt Valor dummy válido
            M->>A: Guardar resultado ficticio
            A-->>M: Confirmación simulada
        else Valor dummy inválido
            M-->>C: Reintento de ejemplo
        end
        deactivate M
    end
    opt Mostrar resumen
        P->>A: Consultar resultado
        A-->>P: Datos dummy
    end
    P-->>U: Simulación terminada
    Note over U,A: Todos los participantes y mensajes son ficticios.
```

## 4. Máquina de estados

```mermaid
stateDiagram-v2
    [*] --> Pendiente
    Pendiente --> Activo: inicio dummy
    state Activo {
        [*] --> Lectura
        Lectura --> Calculo
        Calculo --> Validacion
        Validacion --> Lectura: repetir
        Validacion --> [*]: aceptar
    }
    Activo --> Pausado: pausa simulada
    Pausado --> Activo: continuar
    Activo --> Terminado: cerrar
    Pendiente --> Cancelado: descartar
    Pausado --> Cancelado: descartar
    Cancelado --> [*]
    Terminado --> [*]
```

## 5. Diagrama de clases

```mermaid
classDiagram
    class ElementoDummy {
        +String id
        +String etiqueta
        +validar() bool
    }
    class PaqueteDemo {
        +int cantidad
        +calcular() float
    }
    class MotorDemo {
        +String modo
        +procesar(PaqueteDemo paquete) bool
    }
    class RegistroDemo {
        +String estado
        +guardar() void
    }
    class ValidadorDemo {
        <<interface>>
        +comprobar() bool
    }
    ElementoDummy <|-- PaqueteDemo
    ValidadorDemo <|.. MotorDemo
    MotorDemo --> PaqueteDemo : procesa
    PaqueteDemo "1" *-- "0..*" RegistroDemo : contiene
    MotorDemo ..> RegistroDemo : genera
```

## 6. Entidades y relaciones

```mermaid
erDiagram
    GRUPO_DUMMY ||--o{ ELEMENTO_DUMMY : contiene
    ELEMENTO_DUMMY ||--o{ EVENTO_DUMMY : genera
    LOTE_DUMMY ||--|{ ELEMENTO_DUMMY : agrupa
    GRUPO_DUMMY {
        int id PK
        string nombre
    }
    ELEMENTO_DUMMY {
        int id PK
        int grupo_id FK
        int lote_id FK
        string etiqueta
    }
    EVENTO_DUMMY {
        int id PK
        int elemento_id FK
        string tipo
    }
    LOTE_DUMMY {
        int id PK
        string codigo
    }
```

## 7. Cronograma ficticio

```mermaid
gantt
    title Calendario dummy sin relación con proyectos reales
    dateFormat YYYY-MM-DD
    axisFormat %d/%m
    section Preparación
    Datos sintéticos :done, a1, 2030-01-01, 3d
    Configuración demo :a2, after a1, 2d
    section Simulación
    Lote Alfa :b1, after a2, 5d
    Lote Beta :b2, after a2, 4d
    Revisión dummy :crit, c1, after b1, 2d
    section Cierre
    Hito ficticio :milestone, m1, after c1, 0d
```

## 8. Distribución de valores dummy

```mermaid
pie showData
    title Reparto sintético de 100 unidades
    "Alfa" : 35
    "Beta" : 25
    "Gamma" : 20
    "Delta" : 15
    "Épsilon" : 5
```

## 9. Mapa mental

```mermaid
mindmap
  root((Universo dummy))
    Entrada
      Texto ficticio
      Valores sintéticos
      Unicode ñ Ω 漢字
    Proceso
      Lectura
      Transformación
      Validación
        Caso Alfa
        Caso Beta
    Salida
      Resumen
      Archivo de ejemplo
      Panel ficticio
```

## 10. Grafo denso: 24 nodos y enlaces cruzados

```mermaid
flowchart LR
    A1[Alfa 1] --> B1[Beta 1] & B2[Beta 2] & B3[Beta 3]
    A2[Alfa 2] --> B2 & B3 & B4[Beta 4]
    A3[Alfa 3] --> B3 & B4 & B5[Beta 5]
    A4[Alfa 4] --> B4 & B5 & B6[Beta 6]
    A5[Alfa 5] --> B5 & B6 & B1
    A6[Alfa 6] --> B6 & B1 & B2
    B1 --> C1[Gamma 1] & C4[Gamma 4]
    B2 --> C2[Gamma 2] & C5[Gamma 5]
    B3 --> C3[Gamma 3] & C6[Gamma 6]
    B4 --> C4 & C1
    B5 --> C5 & C2
    B6 --> C6 & C3
    C1 --> D1[Delta 1] & D2[Delta 2]
    C2 --> D2 & D3[Delta 3]
    C3 --> D3 & D4[Delta 4]
    C4 --> D4 & D5[Delta 5]
    C5 --> D5 & D6[Delta 6]
    C6 --> D6 & D1
    D1 -.-> A6
    D6 -.-> A1
```

## 11. Diagrama monoespaciado sin extensiones

```text
┌───────────────────────┐      ┌───────────────────────┐
│ Entrada dummy         │─────▶│ Transformación demo   │
│ α = 12; β = 34        │      │ etiqueta: SIM-042     │
└───────────────────────┘      └───────────┬───────────┘
                                          │
                              ┌───────────▼───────────┐
                              │ Salida ficticia       │
                              │ resultado: 46         │
                              └───────────────────────┘
```

## Criterios de revisión visual

- Los bloques Mermaid se convierten en gráficos si el visor los admite.
- Las etiquetas largas y los caracteres Unicode permanecen legibles.
- Los enlaces, ciclos y cardinalidades no desaparecen.
- Los diagramas anchos admiten desplazamiento o ajuste sin recortar contenido.
- El diagrama de texto conserva espacios, bordes y alineación.
- La página sigue respondiendo al desplazamiento con todos los bloques visibles.
