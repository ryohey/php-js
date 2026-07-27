# php-js 詳細設計

PHP上で直接JSを実行するランタイムの設計文書。設計方針(コンセプト・コア設計判断)を前提に、実装に着手できる粒度まで各コンポーネントを具体化する。

前提となる確定事項:

- 「PHP→WASM→QuickJS」は実測済みで破棄。解釈層は1枚のみ。
- ターゲット仕様は **ES5.1 + Promise/microtaskキュー**。クラス構文・分割代入・ジェネレータ・テンプレートリテラル・イテレータプロトコルは実装しない。入力コードはSWC等でES5へダウンレベル済みであることを前提とする。
- 進捗はtest262(ES5.1サブセット)の通過率で計測する。

参照実装の使い分け: goja=全体構成の手本 / QuickJS=opcode語彙集 / engine262=仕様の抽象操作の正解源 / Peast=パーサとして採用。正規表現エンジンとGCはどこからも移植しない。

---

## 1. 全体アーキテクチャ

```
        (ビルド時 or 初回リクエスト時)                (毎リクエスト)
┌─────────┐   ┌──────────┐   ┌──────────────┐   ┌─────────────────┐
│ JS ソース │ → │ Peast AST │ → │ Compiler      │ → │ バイトコードファイル │
└─────────┘   └──────────┘   │ (AST→bytecode)│   │ <?php return […]; │
                              └──────────────┘   └────────┬────────┘
                                                          │ require
                                                          │ (opcache 共有メモリに常駐)
                                                          ▼
                                              ┌───────────────────────┐
                                              │ VM (単一 while/switch)  │
                                              │ + Realm(遅延初期化)     │
                                              │ + Builtins(PHPネイティブ)│
                                              └───────────────────────┘
```

コンパイルと実行を完全に分離する。shared-nothing環境での典型フローは
「デプロイ時にJSをコンパイルしてバイトコードファイルを生成 → 各リクエストは `require` して即実行」。
パース済みバイトコードはopcacheの共有メモリに乗るため、リクエストごとのパースコストは実質ゼロになる。
開発時はソースのmtimeを見てオンデマンドコンパイルするキャッシュ層を挟む。

## 2. コンパイルパイプライン

### 2.1 パーサ

Peast(ESTree準拠)を採用する。自作しない。

- ES5モードでパースし、対象外構文(class、ジェネレータ等)はコンパイラ側でASTノード種別を見て即エラーにする(黙って誤コンパイルしない)。
- `Function` コンストラクタ / 間接 `eval` のためにPeastはランタイム依存にも含める(コンパイル済みコードだけを動かす本番ではautoloadされないパスに置き、実質コストゼロ)。

### 2.2 コンパイラ構成

1パス目: スコープ解析。関数ごとに `var` / 関数宣言 / 仮引数を収集し、各変数を以下のいずれかに分類する。

| 分類 | 条件 | アクセス方法 |
|---|---|---|
| ローカルスロット | その関数内でのみ参照 | フレーム内の数値インデックス |
| 環境スロット | 内側の関数から参照される(クロージャ捕獲) | 環境レコード経由 (depth, index) |
| 動的 | `with` / 直接`eval` の影響下、またはグローバル | 名前による辞書探索 |

2パス目: ASTを直接バイトコードへ変換(中間IRは設けない。ES5の範囲では最適化パスの余地よりパイプラインの単純さを優先)。関数ごとに独立した「関数テンプレート」を生成し、入れ子関数はテンプレートの子として保持する。

### 2.3 関数テンプレート(バイトコードの単位)

`var_export` 可能な純配列のみで構成する(**後述のopcache戦略の前提条件。PHPオブジェクト・クロージャ・リソースを含めてはならない**)。

```php
[
  'name'     => 'funcName',        // 無名なら ''
  'strict'   => true,
  'nparams'  => 2,
  'nlocals'  => 5,                 // 仮引数含むローカルスロット数
  'nenv'     => 1,                 // この関数が生成する環境レコードのスロット数
  'code'     => [/* フラットint配列: opcodeと即値オペランドの連続 */],
  'consts'   => [/* 定数プール: 文字列・float・正規表現ソース等 */],
  'children' => [/* 入れ子の関数テンプレート */],
  'trys'     => [/* [start, end, catch_pc, finally_pc] の例外テーブル */],
  'lines'    => [/* pc→行番号 のデルタ圧縮テーブル(スタックトレース用) */],
  'flags'    => 0,                 // uses_arguments | has_eval | has_with 等のビットフラグ
]
```

`code` はopcodeも即値オペランドもintのフラット配列。文字列や浮動小数は `consts` のインデックスで参照する。オペランド数はopcodeごとに固定とし、逆アセンブラ/検証器を最初から用意する。

## 3. 値表現

ボクシングしない。JS値とPHP値の対応:

| JS | PHP | 備考 |
|---|---|---|
| number | `int` \| `float` | 下記 3.1 |
| string | `string` | UTF-8保持。下記 6章 |
| boolean | `bool` | そのまま |
| null | `null` | そのまま |
| undefined | `JSUndefined::$instance` | 唯一のシングルトン。`=== JSUndefined::$instance` で判定 |
| object / function | `JSObject` とそのサブクラス | 5章 |

型判定は `is_int` / `is_float` / `is_string` / `is_bool` / `=== null` / `instanceof` の組み合わせで行い、`TypeOps` に集約する。NaNボクシングなどC系の最適化は移植しない(有害)。

### 3.1 数値セマンティクス

JSのnumberは常にdoubleだが、内部表現としては「intで表せる間はint、必要になったらfloat」とする。PHPの算術がintオーバーフロー時に自動でfloatへ昇格するため、加減乗はネイティブ演算子をそのまま使える。個別対応が必要な箇所:

- **除算**: PHPの `/` は割り切れるint同士でintを返すが、JS的にはどちらもnumberなので問題ない。ゼロ除算のみ `INF/-INF/NAN` を明示的に返す(PHPは `DivisionByZeroError` を投げるため)。
- **剰余**: PHPの `%` はint専用。オペランドにfloatを含む場合は `fmod()` に分岐。
- **単項マイナス**: `-0` を作れるのはfloatのみ。`0` (int) への `NEG` は `-0.0` (float) を返す。
- **ビット演算** (`& | ^ << >> >>>` `~`): 仕様どおり `ToInt32` / `ToUint32` を通す。64bit PHP intの上で `((x & 0xFFFFFFFF) ^ 0x80000000) - 0x80000000` 型のマスクで実装。`>>>` は `($x & 0xFFFFFFFF) >> $n` で自然に符号なしになる。
- **厳密等価**: PHPの `===` はint/floatを型違いで弾くため使えない。数値同士は `==`(PHPの数値比較はIEEE準拠、`NAN == NAN` はfalseで期待どおり)、それ以外は型タグ比較+値比較を `TypeOps::strictEquals` に実装。`+0 === -0` はPHPの `==` でtrueになり仕様どおり。
- **ToString(number)**: JSの数値文字列化(最短表現)はPHPの文字列化と一致しないため専用実装。`var_export(1e21)` 等の指数表記境界、`-0` → `"0"`、整数値floatの `"1"` 化を仕様(Number::toString)どおりに実装する。ここはtest262で最も割れやすい箇所なので初期から専用テストを持つ。

仕様の抽象操作(`ToNumber` `ToPrimitive` `ToInt32` `ToString` `SameValue` …)は `Conversions` クラスに1関数=1抽象操作で対応させ、engine262と突き合わせられる形にする。

## 4. 実行モデル(VM)

### 4.1 ディスパッチループ

VM本体は単一メソッド内の `while (true) { switch ($op) }` に閉じ込める。**JS関数呼び出しにPHPのコールスタックを使わない。** フレームは自前のスタック(PHP配列)で管理し、`CALL` はフレームpush+`$pc`差し替え、`RETURN` はフレームpopで実現する。

理由(方針の再掲+具体化):

- PHP関数呼び出しコストが支配的であり、JSレベルの呼び出しをPHP関数呼び出しに写像すると勝ち目がない。
- フレームが自前データであれば、ジェネレータ/async/深い再帰を「フレームの退避・復元」として後付けできる(PHPコールスタックに載せた瞬間にこの道が閉じる)。

ホットパスの規律:

- ループ内ではプロパティアクセス・メソッド呼び出しを避け、フレームの中身(`$stack`, `$sp`, `$pc`, `$code`, `$consts`, `$locals`)をローカル変数に展開して回す。フレーム切り替え時のみ退避/復元する。
- 頻出パターンはスーパーインストラクション化の余地を残す(`GET_LOCAL n` + `PUSH_CONST k` + `ADD` → `ADD_LOCAL_CONST n k` 等)。ただし初期実装では作らず、ベンチマーク後に導入する。

### 4.2 フレームとスタック

```php
// 概念構造(実装ではプロパティアクセスを避けるため並行配列 or 配列のリストにする)
$frame = [
  $template,   // 関数テンプレート(共有・読み取り専用)
  $locals,     // ローカルスロット配列
  $env,        // 環境レコードチェーンの先頭(なければ null)
  $thisVal,
  $retPc,      // 呼び出し元の復帰pc
  $retSp,      // 呼び出し元のスタックポインタ
];
```

オペランドスタックは全フレーム共用の1本の配列(`$stack` + `$sp`)。関数テンプレートにはコンパイル時に最大スタック深度を記録し、`CALL`時にまとめて確保余地を確認する。

### 4.3 呼び出し規約

- `CALL argc`: スタック上に `[func, this, arg1..argN]` を積んだ状態で実行。callee が
  - **JS関数(バイトコード)** → フレームpushして継続(PHP関数呼び出しなし)。
  - **ネイティブ関数** → レジストリのPHP callable を直接呼ぶ。戻り値をスタックに積む。
- `NEW argc`: `prototype` 取得→ `JSObject` 生成→ `this` に渡して `CALL` 同様。戻り値がオブジェクトでなければ生成したオブジェクトを結果にする。
- **ネイティブ→JSコールバック再入**(`Array.prototype.map` のコールバック等): VMに「指定フレーム深度に戻るまで実行する」再入口 `runUntil(int $frameDepth)` を用意する。ネイティブ実装はコールバックJS関数をフレームpushして `runUntil` を呼ぶ。再入はPHPコールスタックを1段消費するが、`map` 1回につき1段(要素ごとではない)に抑える設計とする。

### 4.4 例外処理

- JSの `throw` はVM内制御フローで処理する。各関数テンプレートの例外テーブル(`trys`)を `pc` で引き、ハンドラがあれば `pc` をcatch/finallyへ差し替え、なければフレームをpopして伝播する。**PHP例外をJSの制御フローに使わない**(try/catchの出入りが頻繁なコードでPHP例外は高すぎる)。
- ネイティブ境界だけはPHP例外 `JSThrowSignal`(JS例外値を運ぶ)を使う。ネイティブ実装内でJS例外を投げたい/コールバックから伝播してきた場合はこれをthrowし、VMの呼び出し箇所でcatchして通常の伝播処理に合流させる。
- `finally` はcatch同様に例外テーブルで処理し、「finally完了後の継続先」(再throw / return続行 / 通常続行)をスタック上の完了レコードで表現する(QuickJSの `OP_gosub` 相当は作らず、完了種別を積む方式)。

### 4.5 スコープとクロージャ

- 捕獲されない変数はフレームの `$locals` スロット(数値インデックス)。
- 捕獲される変数は環境レコード `JSEnv { ?JSEnv $parent; array $slots; }` に置き、`GET_ENV depth,index` でチェーンを辿る。環境レコードは関数入場時に(必要な関数のみ)生成する。
- `with` と直接 `eval` を含むスコープは動的解決にフォールバックする(`flags` で関数単位にマーク)。この場合のみ名前ベースの探索命令(`GET_VAR_DYN` 等)を出力する。test262通過に直接evalは必須のため、機能自体は落とさない。
- `arguments` は使用が検出された関数でのみ実体化する(exoticなマップ動作は非strict時のみ。仕様どおりだがコストは使用関数に限定される)。

## 5. オブジェクトモデル

### 5.1 JSObject

hidden class・inline cacheは自作しない。プロパティ格納はPHP配列に委譲し、ハッシュ探索はPHP処理系(zend hash)に任せる。最適化の対象は「PHPレベルの命令数を減らすこと」のみ。

```php
class JSObject {
    public array $props = [];        // name => 生の値(データプロパティのfast path)
    public ?array $descs = null;     // name => [getter, setter, flags](必要になった時だけ)
    public ?JSObject $proto;
    public bool $extensible = true;
    public ?string $nativeId = null; // レルムスナップショット用の由来ID(11章)
}
```

- 通常のデータプロパティ(writable/enumerable/configurable全部true)は `$props` に生値で置く。`defineProperty` でそれ以外の属性やアクセサが指定された時だけ `$descs` にエントリを作る。**getには `$descs === null` なら記述子チェック自体をスキップするfast path**が通る。
- 取得のfast path: `$obj->props[$k] ?? <miss処理>`。値がJS `null`(=PHP null)のときだけ `??` が誤発火するため、missルートの先頭で `array_key_exists` により補正する(nullを格納するケースだけが遅くなる、を許容する)。
- プロトタイプチェーンはPHPオブジェクト参照を素直に辿る。
- プロパティキーはES5なので文字列のみ(Symbolなし)。PHP配列が数値文字列キーをintに正規化する挙動は、JS側も `"0"` と `0` を同一視するため実害なし。ただしキーの列挙時に `(string)` 正規化を挟む。

### 5.2 exotic オブジェクト

クラス継承で表現する(タグ+ifよりディスパッチが安い):

- `JSArray`: 要素は `$props` と分離した `$elements`(PHP配列)+ `$length` に持つ。インデックスが密な間はPHPのpacked arrayに乗る。`length` への代入による切り詰め、`length` 自動更新をここで実装。疎になったら同じ `$elements` の中でハッシュ化するだけ(PHPが自動でやる)。
- `JSFunction`: `$template`(関数テンプレート参照)+ `$env`(定義時環境)+ `$realm`。
- `JSNativeFunction`: `$fnId`(**ネイティブ関数レジストリのstring ID**。PHP Closureを直接持たない — 11章のシリアライズ制約のため)+ `$arity` + `$name`。
- `JSBoundFunction`, `JSRegExpObject`, `JSDateObject`, プリミティブラッパー(`JSStringObject` 等)。
- グローバルオブジェクトは「プロパティmiss時に組み込みテーブルを引くフック」を持つ `JSGlobalObject`(11章の遅延初期化の要)。

### 5.3 組み込みライブラリ

- すべてPHPネイティブ実装(self-hosted JSにしない。VM再入コストと初期化コストの両方で不利)。
- ホットな高階関数(`Array.prototype.map/filter/forEach/reduce`, `String.prototype.replace` のコールバック形)はPHPループで回し、コールバック呼び出しのみ `runUntil` でVMに戻す。
- ネイティブ関数はすべて `BuiltinRegistry` に `'Array.prototype.map' => callable` の形で登録し、ヒープ側はIDで参照する。シグネチャは `fn(VM $vm, mixed $thisVal, array $args): mixed` に統一。

## 6. 文字列

内部表現はUTF-8のまま保持し、ASCIIフラグでfast pathを取る。

- PHP stringをそのまま使う(ラッパーオブジェクトを作らない)ため、ASCIIフラグ等のメタデータは文字列自体に持てない。**メタデータはVM側のmemoizeテーブル**(直近アクセス文字列の `[len16, isAscii, オフセット変換テーブル]` を持つ小さなLRU)に置く。ループ内で同じ文字列の `length` や `charCodeAt` を叩くパターンを1回の解析で吸収する。
- ASCII判定は `!preg_match('/[\x80-\xFF]/', $s)` 相当(実装は `strspn` ベース)。ASCIIなら `.length` = `strlen`、`charCodeAt` = `ord` で完結。
- 非ASCII時のみUTF-16セマンティクスの遅いパスへ: UTF-16コードユニット数の計算、コードユニット⇔バイトオフセットの変換テーブル生成(サロゲートペア対応)。
- `fromCharCode` / `charCodeAt` が孤立サロゲートを作れるため、内部表現は厳密には **WTF-8**(孤立サロゲートをCESU的に許容するUTF-8拡張)とする。正規表現・ホスト出力境界でのみ妥当性を扱う。
- 文字列連結はPHPの `.` に直結(ここがrefcount GCとopcacheに次ぐPHP委譲の勝ち筋)。

## 7. バイトコード命令セット

QuickJSのopcode粒度を語彙集として参照しつつ、スタックマシンとして必要十分な~90命令に絞る。カテゴリと代表例:

| カテゴリ | 命令例 |
|---|---|
| 定数 | `PUSH_CONST k` `PUSH_INT i`(小整数即値) `PUSH_TRUE/FALSE/NULL/UNDEF` |
| スタック | `DUP` `DUP2` `POP` `SWAP` |
| 変数 | `GET_LOCAL n` `SET_LOCAL n` `GET_ENV d,i` `SET_ENV d,i` `GET_GLOBAL k` `SET_GLOBAL k` `GET_VAR_DYN k` `TYPEOF_VAR k` |
| プロパティ | `GET_PROP k`(キー静的) `SET_PROP k` `GET_ELEM`(キー動的) `SET_ELEM` `DEL_PROP` `DEFINE_DATA k`(リテラル用・setter不発火) `DEFINE_GETTER/SETTER k` |
| 演算 | `ADD SUB MUL DIV MOD NEG INC DEC` `BAND BOR BXOR BNOT SHL SHR USHR` `NOT` `EQ NEQ SEQ SNEQ LT LE GT GE` `TYPEOF` `IN` `INSTANCEOF` |
| 制御 | `JMP a` `JT a` `JF a` `JT_KEEP a` `JF_KEEP a`(`&&`/`||`用) `SWITCH_SEQ`(caseの===比較) |
| 呼出 | `CALL argc` `CALL_METHOD argc` `NEW argc` `RETURN` `RETURN_UNDEF` |
| 生成 | `NEW_OBJECT` `NEW_ARRAY n` `NEW_FUNC idx` `NEW_REGEXP k` `PUSH_THIS` `ARGUMENTS` |
| 例外 | `THROW` `TRY_ENTER idx` `TRY_LEAVE` `FINALLY_END` |
| 列挙 | `FOR_IN_INIT` `FOR_IN_NEXT a`(スナップショット済みキーリストを内部イテレータとして積む) |
| その他 | `WITH_ENTER` `WITH_LEAVE` `DEBUGGER`(no-op) |

設計ルール:

- `ADD` は数値+数値のfast path(PHPの `+` に直結)を命令内に持ち、文字列連結/ToPrimitiveはスローパスに分岐。
- 比較・等価も同様に「両オペランドが数値/文字列なら直結、それ以外は `Conversions` 呼び出し」の2段構え。
- `GET_PROP` はプロトタイプチェーン探索を命令内にインライン展開する(メソッド呼び出しにしない)。exotic(`JSArray` の数値インデックス等)は `instanceof` 1回で分岐。

## 8. 正規表現: JS → PCRE2 変換層

libregexpは移植しない。JS正規表現リテラル/コンストラクタのパターンをPCRE2構文へ変換する `RegExpTranslator` を実装する。変換はコンパイル時(リテラル)またはRegExpオブジェクト生成時(動的)に1回行い、結果のPCREパターンをオブジェクトにキャッシュする。

| JS | PCRE2での扱い |
|---|---|
| `g` フラグ | パターンには反映せず、`lastIndex` ループを呼び出し側(exec/replace実装)で制御 |
| `y` フラグ | `preg_match` の `$offset` + `A`(anchored)修飾子 |
| `i` `m` | そのまま `i` `m` |
| `u` フラグ | `u` 修飾子(UTF)+ PCRE2のUCP系エスケープで大半吸収。`\u{XXXX}` → `\x{XXXX}` |
| `\uXXXX` `\xXX` | `\x{XXXX}` へ書き換え |
| `[]`(空クラス) | JSでは「何にもマッチしない」→ `(?!)` に置換。`[^]` → `[\s\S]` |
| `$` `^` | 同義(m時も同義) |
| 8進エスケープ・AnnexB系 | 変換器で明示的に処理(PCREと解釈が割れるものは書き換え) |
| 名前付きグループ等ES2018+ | 対象外としてコンパイルエラー(SWCは正規表現をダウンレベルしないため、**入力側の制約として文書化**) |

オフセット問題: PCREのオフセットはバイト単位、JSの `lastIndex` はUTF-16コードユニット単位。ASCII文字列なら恒等、非ASCII時のみ6章の変換テーブルでバイト⇔UTF-16を変換する。

既知の妥協点(文書化して受容): 非 `u` フラグ正規表現がサロゲートペアをコードユニット単位で扱うJSセマンティクスは、UTF-8上のPCREでは完全再現しない。test262の該当ケースはスキップリストで管理する。

## 9. Promise / microtask

- ジョブキューは `Realm` 内の単純なFIFO配列。`Promise` 本体はPHPネイティブ実装(then連鎖の解決処理をVM再入なしで行い、ユーザコールバックの呼び出しのみVMに入る)。
- 実行モデル: ホストAPI `$runtime->run($bytecode)` は「同期実行 → キューが空になるまでmicrotaskをドレイン」までを1呼び出しで行う。`setTimeout` 等のタスクキュー(macrotask)はコアには含めず、ホスト統合層(SSR用途なら `flushUntilIdle()`)として分離する。
- unhandled rejection はドレイン完了時に検出してホストのフックへ通知する。

## 10. エラー処理とスタックトレース

- `Error` 系オブジェクトの生成時に、自前フレームスタックを走査して `テンプレート名 + lines テーブル` から `stack` 文字列を構築する。フレームが自前データなのでPHPの `debug_backtrace` に依存しない(むしろ何も映らない)。
- VM内部バグとJS例外を厳密に区別する: JS例外は値として伝播、PHP例外がVM境界まで漏れたらそれはランタイムのバグ。

## 11. PHP実行環境への適合(shared-nothing対応)

**この章の制約は後付け不可能なため、他章の設計がすべてこの制約に従っていることを実装レビューの必須項目とする。**

### 11.1 バイトコード = opcache常駐

- コンパイル結果は `<?php return [ /* 関数テンプレート木 */ ];` 形式のファイルとして出力する。
- opcacheはこのファイルを共有メモリ上のimmutable配列としてキャッシュするため、2回目以降の `require` はデシリアライズもコピーも走らない(immutable array最適化)。**関数テンプレートに純配列以外を含めてはならない**のはこのため。
- キャッシュキーはソースのhash + コンパイラバージョン。バイトコードフォーマットにバージョン番号を埋め、非互換時は再コンパイル。

### 11.2 レルムの遅延初期化(フェーズ1の必須要件)

- リクエストごとに `Realm` を生成するが、組み込みオブジェクトは触られるまで作らない。
  - グローバル変数参照のmiss時に `JSGlobalObject` が `BuiltinRegistry::GLOBALS` テーブル(名前→初期化子ID)を引いて実体化する。
  - `Object.prototype` / `Array.prototype` 等は `Realm` のmemoizeアクセサ(`$realm->arrayPrototype()`)経由でのみ取得し、初回アクセス時に構築する。
  - 「`console.log` と `JSON` しか触らないリクエストでは、それ以外の組み込みは1オブジェクトも生成されない」を受け入れ基準とする。

### 11.3 レルムスナップショット(フェーズ2。ただし構造制約は初期から適用)

初期化済みレルム(組み込み+ユーザの初期化コード実行後のヒープ)をファイルに書き出し、リクエスト開始時に復元する構想。これを可能にするため、**ヒープに置けるものを設計初期から制限する**:

1. ヒープ(JSObjectのフィールドから到達可能な値)に置けるのは: JS値(3章の表)+ `JSEnv` + 関数テンプレート参照のみ。
2. **PHP Closure・リソース・外部PHPオブジェクトをヒープに直接持たない。** ネイティブ関数は `$fnId`(string)でレジストリを間接参照する(5.2)。ホスト連携オブジェクトも同様にID参照とする。
3. 循環参照を含むため `var_export` の直接適用は不可。スナップショットは「全オブジェクトにIDを振ったフラットテーブル(参照はID)」形式でエクスポートし、復元時に2パスで再構築する。このテーブル自体は純配列なのでopcacheに乗る。
4. 復元コスト(オブジェクト再生成のO(ヒープサイズ))が遅延初期化より速いかは**計測して判断**する。負けるならフェーズ2は破棄し、遅延初期化のみで運用する(構造制約はどのみちデバッグダンプ・テスト用途に有効なので維持する)。

## 12. test262 運用

- `tests/test262/` にランナーを実装: フロントマター(negative / includes / flags)のパース、`sta.js` `assert.js` 等のharness注入、strict/non-strict両モード実行。
- 対象フィルタ: `language/` と `built-ins/` からES5.1相当を includeリスト で選別し、対象外機能(class、generator、Symbol、Proxy…)は featureタグ+パス で除外する。
- スキップリスト(既知の妥協: 8章の正規表現サロゲート等)は理由コメント必須の1ファイルで管理する。
- CIで通過率を数値として出力し、**通過率の推移だけを進捗指標とする**(「特定フレームワークが動くか」は指標にしない)。リグレッションは通過数の減少で機械的に検出する。

## 13. ディレクトリ構成

```
src/
  Compiler/      # Peast AST → 関数テンプレート(ScopeAnalyzer, Emitter, ConstPool)
  Vm/            # ディスパッチループ, フレーム管理, runUntil再入口
  Runtime/       # Realm, JSObject系, JSEnv, Conversions, TypeOps, StringOps
  Builtins/      # BuiltinRegistry と各組み込み(Global, ObjectB, ArrayB, StringB, JSONB, MathB, DateB, ErrorB, PromiseB, RegExpB)
  RegExp/        # RegExpTranslator, JSRegExp(PCREキャッシュ・lastIndex制御)
  Cache/         # バイトコードファイルの出力/require, バージョン管理
  Host/          # ホスト統合(タスクキュー, console, タイマ等コア外機能)
bin/
  phpjs          # CLI: compile / run / disasm
tests/
  unit/          # Conversions・Translator・命令単位のPHPUnitテスト
  test262/       # ランナー, includeリスト, スキップリスト
```

命名空間は `PhpJs\`。PHP 8.2+ を要求(readonly, enum, 最新opcacheの immutable array 最適化を前提とするため)。

## 14. マイルストーン

| # | 内容 | 完了条件 |
|---|---|---|
| M0 | 足場: composer, Peast導入, CLI骨格, test262ランナー | ランナーが0%を報告できる |
| M1 | 式・文のコンパイルとVM(関数なし): 算術, 変数, 制御構文, `Conversions` | test262 `language/expressions` `language/statements` の対象分が概ね通る |
| M2 | 関数・クロージャ・例外・`arguments`・`this` | `language/` 対象分 |
| M3 | オブジェクトモデルと主要組み込み(Object/Array/String/Number/Boolean/Math/JSON/Error) | `built-ins/` 対象分の通過率が主指標になる |
| M4 | 文字列UTF-16セマンティクス完備+RegExp変換層 | `built-ins/RegExp` `built-ins/String` 対象分 |
| M5 | Promise + microtask + Date | 対象スイート通過 |
| M6 | バイトコードファイル出力+opcache検証+レルム遅延初期化の計測 | ベンチ: リクエストあたり初期化コストの実測レポート |
| M7 | 実戦投入検証: SWCでES5化したReactのSSRを走らせ、素のPHPレンダラと比較計測 | 性能レポート(採否判断材料) |

M1完了時点からtest262通過率をCIで常時計測する。

## 15. リスクと未決事項

- **ディスパッチコストの下限**: PHPの `switch(int)` ディスパッチがそもそも遅すぎる可能性。M1完了時にfib/ループ系マイクロベンチで「QuickJS比◯倍」を実測し、スーパーインストラクション・opcode並び最適化の投資判断をする。
- **直接eval**: 動的スコープ化の波及が大きい。test262通過に必要な範囲(自スコープ参照)から実装し、汚染フラグで隔離する。
- **`Function` コンストラクタ**: ランタイムにPeast+コンパイラを含める必要がある。opcacheに乗らない動的コンパイルとして許容(頻度は低い前提)。
- **正規表現の意味論ギャップ**(8章): スキップリストで管理し、実アプリで踏んだものから個別対応。
- **SWCダウンレベルの穴**: 正規表現構文・一部組み込み(`Object.assign` 等はpolyfill任せ)はSWCが変換しない。入力コードの前提条件を `docs/input-requirements.md` として別途明文化する。
- **レルムスナップショットの採算**(11.3): 計測で判断。負けたら破棄可能な設計にしてある。
