grammar Golampi;

//  PROGRAMA

program
    : decl* EOF                                             # P
    ;

decl
    : funcDecl                                              # FunctionDeclaration
    | 'const' ID type_ '=' e ';'?                           # ConstDeclGlobal
    ;



//  FUNCIONES
//  void, retorno simple, retorno múltiple

funcDecl
    : 'func' ID '(' (param (',' param)*)? ')' block                                          # FuncDeclVoid
    | 'func' ID '(' (param (',' param)*)? ')' returnType block                               # FuncDeclReturn
    | 'func' ID '(' (param (',' param)*)? ')' '(' returnType (',' returnType)* ')' block     # FuncDeclMultiReturn
    ;

//  Parámetros 
// arrayType cubre 1D, 2D, … ND: '[' INT ']' '[' INT ']' 
param
    : ID type_                                              # Parametro
    | ID arrayType type_                                    # ParametroArrayND
    | ID '*' type_                                          # ParametroPointer
    | ID '*' arrayType type_                                # ParametroPointerArrayND
    ;


//  BLOQUE

block
    : '{' stmt* '}'                                         # B
    ;



//  SENTENCIAS

stmt
    // Declaración de arreglos N-dimensionales
    : 'var' ID arrayType type_ ';'?                         # VarArrayND
    | 'var' ID arrayType type_ '=' arrayLit ';'?            # VarArrayNDInit

    // Declaración de variables escalares 
    | 'var' ID type_ '=' e ';'?                             # VarDeclInit
    | 'var' ID type_ ';'?                                   # VarDeclEmpty
    | 'var' ID (',' ID)+ type_ '=' e (',' e)* ';'?          # VarDeclMulti
    | 'var' ID (',' ID)+ '=' e ';'?                         # VarDeclMultiShort

    //  Constantes 
    | 'const' ID type_ '=' e ';'?                           # ConstDeclStmt

    // Declaración corta 
    | ID ':=' e ';'?                                        # ShortVarDecl
    | ID ':=' arrayLit ';'?                                 # ShortVarArrayND
    | ID (',' ID)+ ':=' e (',' e)* ';'?                     # MultiShortVarDecl

    //  Asignaciones escalares 
    | ID '=' e ';'?                                         # AssignStmt
    | ID '+=' e ';'?                                        # PlusAssignStmt
    | ID '-=' e ';'?                                        # MinusAssignStmt
    | ID '*=' e ';'?                                        # StarAssignStmt
    | ID '/=' e ';'?                                        # SlashAssignStmt
    | ID '++' ';'?                                          # IncStmt
    | ID '--' ';'?                                          # DecStmt

    // Punteros 
    | '*' ID '=' e ';'?                                     # DerefAssignStmt

    // Asignación de arreglo N-D: a[i][j][k] = e 
    //  ID seguido de UNO O MÁS índices, luego '= e'
    | ID ('[' e ']')+ '=' e ';'?                            # ArrayAssignND

    // Salida estándar 
    | 'fmt.Println' '(' (e (',' e)*)? ')' ';'?              # PrintlnStmt

    // Llamada a función como sentencia 
    | ID '(' (e (',' e)*)? ')' ';'?                         # FuncCallStmt

    // Control de flujo 
    | 'if' e block ('else' block)? ';'?                     # IfStmt
    | 'if' e block 'else' stmt                              # IfElseIfStmt

    // Bucles 
    | 'for' e block ';'?                                    # ForWhileStmt
    | 'for' block ';'?                                      # ForInfiniteStmt
    | 'for' varForInit ';' e ';' forPost block ';'?         # ForClassicStmt

    // Switch 
    | 'switch' e? '{' switchCase* '}' ';'?                  # SwitchStmt

    // Transferencia 
    | 'return' (e (',' e)*)? ';'?                           # ReturnStmt
    | 'break' ';'?                                          # BreakStmt
    | 'continue' ';'?                                       # ContinueStmt

    //  Bloque anidado 
    | block ';'?                                            # BlockStmt
    ;



//  FOR — inicialización y post
varForInit
    : 'var' ID type_ '=' e                                  # ForVarInit
    | ID ':=' e                                             # ForShortInit
    ;

forPost
    : ID '++'                                               # ForIncPost
    | ID '--'                                               # ForDecPost
    | ID '+=' e                                             # ForPlusAssignPost
    | ID '-=' e                                             # ForMinusAssignPost
    | ID '*=' e                                             # ForMulAssignPost
    | ID '/=' e                                             # ForDivAssignPost
    | ID '=' e                                              # ForAssignPost
    ;


//  ARREGLOS N-DIMENSIONALES
//  arrayType  -> una o más dimensiones: [2] | [2][3] | [2][3][4] …
//  arrayLit   -> literal anidado recursivo
//  arrayRow   -> fila interior (puede contener más arrayRow o exprs)



// Una o más dimensiones: '[' INT_LIT ']' 
arrayType
    : ('[' INT_LIT ']')+
    ;

// Literal de arreglo ND: [d1][d2]…type_{ contenido }
// El contenido puede ser filas anidadas o expresiones planas (1D)
arrayLit
    : arrayType type_ '{' arrayContent '}'
    ;

// Contenido: lista de filas o lista de expresiones
arrayContent
    : arrayRow (',' arrayRow)* ','?                         # ArrayContentRows
    | (e (',' e)* ','?)?                                    # ArrayContentExprs
    ;

// Fila: puede ser { filas } (ND) o expresiones (última dim)
arrayRow
    : '{' arrayContent '}'
    ;



//  SWITCH
switchCase
    : 'case' e ':' stmt*                                    # CaseStmt
    | 'default' ':' stmt*                                   # DefaultStmt
    ;



//  TIPOS DE RETORNO
returnType
    : type_                                                 # ReturnTypeSimple
    | arrayType type_                                       # ReturnTypeArrayND
    ;



//  TIPOS ESCALARES
type_
    : 'int32'                                               # TypeInt32
    | 'int'                                                 # TypeInt
    | 'float32'                                             # TypeFloat32
    | 'bool'                                                # TypeBool
    | 'rune'                                                # TypeRune
    | 'string'                                              # TypeString
    | '*' type_                                             # TypePointer
    ;



//  EXPRESIONES
//  Precedencia (de menor a mayor):
//    1. ||
//    2. &&
//    3. == !=
//    4. < > <= >=
//    5. + -
//    6. * / %
//    7. unario - ! & *
//    8. primario
e
    //  Agrupación 
    : '(' e ')'                                             # GroupExpr

    // Funciones embebidas 
    | 'fmt.Println' '(' (e (',' e)*)? ')'                   # PrintlnExpr
    | 'len'    '(' e ')'                                    # LenExpr
    | 'now'    '(' ')'                                      # NowExpr
    | 'substr' '(' e ',' e ',' e ')'                        # SubstrExpr
    | 'typeOf' '(' e ')'                                    # TypeOfExpr

    //  Casts 
    | 'int32'   '(' e ')'                                   # CastInt32
    | 'int'     '(' e ')'                                   # CastInt
    | 'float32' '(' e ')'                                   # CastFloat32
    | 'bool'    '(' e ')'                                   # CastBool
    | 'string'  '(' e ')'                                   # CastString
    | 'rune'    '(' e ')'                                   # CastRune

    //  Llamada a función 
    | ID '(' (e (',' e)*)? ')'                              # FuncCallExpr

    // Literales 
    | BOOL_LIT                                              # BoolLit
    | INT_LIT                                               # IntLit
    | FLOAT_LIT                                             # FloatLit
    | STRING_LIT                                            # StringLit
    | RUNE_LIT                                              # RuneLit
    | 'nil'                                                 # NilLit

   
    | ID ('[' e ']')+                                       # ArrayAccessND

    //  Variable 
    | ID                                                    # IdExpr

    // Unarios 
    | '-' e                                                 # NegExpr
    | '!' e                                                 # NotExpr
    | '&' ID                                                # RefExpr
    | '*' ID                                                # DerefExpr

    // Binarios (orden de precedencia) 
    | e op=('*'|'/'|'%') e                                  # MulExpr
    | e op=('+'|'-') e                                      # AddExpr
    | e op=('<'|'>'|'<='|'>=') e                            # RelExpr
    | e op=('=='|'!=') e                                    # EqExpr
    | e op='&&' e                                           # AndExpr
    | e op='||' e                                           # OrExpr
    ;



//  TOKENS
BOOL_LIT     : 'true' | 'false' ;
INT_LIT      : [0-9]+ ;
FLOAT_LIT    : [0-9]+ '.' [0-9]+ ;
RUNE_LIT     : '\'' ( ~['\\\r\n] | '\\' . ) '\'' ;
STRING_LIT   : '"'  ( ~["\\\r\n] | '\\' . )* '"' ;
ID           : [a-zA-Z_][a-zA-Z0-9_]* ;

LINE_COMMENT  : '//' ~[\r\n]* -> skip ;
BLOCK_COMMENT : '/*' .*? '*/' -> skip ;
WS            : [ \t\r\n]+ -> skip ;
