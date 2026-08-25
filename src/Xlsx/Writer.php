<?php
/**
 * Escritor mínimo de .xlsx — o suficiente para gerar um backup reimportável.
 *
 * Produz um Office Open XML válido com abas, texto, número e data. Usa inline
 * strings em vez de sharedStrings: gera um arquivo um pouco maior, mas dispensa
 * a tabela de strings e torna o formato trivial de auditar.
 *
 * Todo texto passa por escapeXml antes de entrar no documento — um valor com
 * "&" ou "<" vindo de um lançamento corromperia o XML e o arquivo não abriria.
 */

declare(strict_types=1);

namespace MinhasContas\Xlsx;

use ZipArchive;

final class Writer
{
    public const NORMAL = 0;
    public const NEGRITO = 1;
    public const DATA = 2;
    public const CABECALHO = 3;
    public const DINHEIRO = 4;
    public const TITULO = 5;

    /** @var list<string> */
    private array $abas = [];

    /** @var array<int, array<int, array<int, array{valor: mixed, estilo: int}>>> aba => linha => coluna => célula */
    private array $celulas = [];

    /** @var array<int, list<float>> aba => largura de cada coluna, a partir de A */
    private array $larguras = [];

    /** @var array<int, int> aba => última linha congelada no topo */
    private array $congeladas = [];

    /** @var array<int, string> aba => intervalo do filtro automático */
    private array $filtros = [];

    private int $abaAtual = -1;

    public function aba(string $nome): self
    {
        $this->abas[] = mb_substr($nome, 0, 31);
        $this->abaAtual = count($this->abas) - 1;
        $this->celulas[$this->abaAtual] ??= [];
        return $this;
    }

    /** Grava em coordenada 1-based, como ws.cell(linha, coluna) do openpyxl. */
    public function set(int $linha, int $coluna, mixed $valor, int $estilo = self::NORMAL): self
    {
        if ($valor === null || $valor === '') {
            return $this;
        }
        $this->celulas[$this->abaAtual][$linha][$coluna] = ['valor' => $valor, 'estilo' => $estilo];
        return $this;
    }

    /** Grava por referência de célula: ref('G1', 1234.5). */
    public function ref(string $referencia, mixed $valor, int $estilo = self::NORMAL): self
    {
        if (preg_match('/^([A-Z]+)(\d+)$/i', $referencia, $m) !== 1) {
            throw new \InvalidArgumentException("Referência inválida: {$referencia}");
        }
        return $this->set((int) $m[2], self::indiceDaColuna($m[1]), $valor, $estilo);
    }

    /**
     * Escreve uma linha de cabeçalho em negrito a partir de uma coluna.
     *
     * @param list<string> $titulos
     */
    public function cabecalho(int $linha, int $primeiraColuna, array $titulos, int $estilo = self::NEGRITO): self
    {
        foreach ($titulos as $i => $titulo) {
            $this->set($linha, $primeiraColuna + $i, $titulo, $estilo);
        }
        return $this;
    }

    /**
     * Largura das colunas da aba atual, em caracteres, a partir da coluna A.
     *
     * Sem isto toda coluna sai com a largura padrão e "Participação de Lucros"
     * aparece cortado — quem abre o arquivo acha que o dado veio truncado.
     *
     * @param list<float|int> $larguras
     */
    public function larguras(array $larguras): self
    {
        $this->larguras[$this->abaAtual] = array_map(floatval(...), $larguras);
        return $this;
    }

    /** Mantém as primeiras linhas fixas ao rolar a aba atual. */
    public function congelar(int $ateLinha = 1): self
    {
        $this->congeladas[$this->abaAtual] = $ateLinha;
        return $this;
    }

    /** Liga o filtro automático da aba atual num intervalo ("A1:J1"). */
    public function filtro(string $intervalo): self
    {
        $this->filtros[$this->abaAtual] = $intervalo;
        return $this;
    }

    public function salvar(string $caminho): void
    {
        $zip = new ZipArchive();
        if ($zip->open($caminho, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException("Não foi possível criar {$caminho}");
        }

        $zip->addFromString('[Content_Types].xml', $this->contentTypes());
        $zip->addFromString('_rels/.rels', $this->relsRaiz());
        $zip->addFromString('xl/workbook.xml', $this->workbook());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->relsWorkbook());
        $zip->addFromString('xl/styles.xml', $this->styles());

        foreach ($this->abas as $i => $_) {
            $zip->addFromString('xl/worksheets/sheet' . ($i + 1) . '.xml', $this->sheet($i));
        }

        $zip->close();
    }

    // ------------------------------------------------------------- partes

    private function contentTypes(): string
    {
        $abas = '';
        foreach ($this->abas as $i => $_) {
            $abas .= '<Override PartName="/xl/worksheets/sheet' . ($i + 1) . '.xml"'
                . ' ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml"'
            . ' ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml"'
            . ' ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . $abas
            . '</Types>';
    }

    private function relsRaiz(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1"'
            . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument"'
            . ' Target="xl/workbook.xml"/></Relationships>';
    }

    private function workbook(): string
    {
        $sheets = '';
        foreach ($this->abas as $i => $nome) {
            $sheets .= sprintf(
                '<sheet name="%s" sheetId="%d" r:id="rId%d"/>',
                self::escapeXml($nome),
                $i + 1,
                $i + 1
            );
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets>' . $sheets . '</sheets></workbook>';
    }

    private function relsWorkbook(): string
    {
        $rels = '';
        foreach ($this->abas as $i => $_) {
            $rels .= sprintf(
                '<Relationship Id="rId%d"'
                . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"'
                . ' Target="worksheets/sheet%d.xml"/>',
                $i + 1,
                $i + 1
            );
        }
        // O id dos estilos vem depois das abas para não colidir com elas.
        $idEstilos = count($this->abas) + 1;
        $rels .= sprintf(
            '<Relationship Id="rId%d"'
            . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles"'
            . ' Target="styles.xml"/>',
            $idEstilos
        );

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . $rels . '</Relationships>';
    }

    /**
     * Os estilos das constantes desta classe, na ordem: normal, negrito, data,
     * cabeçalho, dinheiro e título. Os índices de cellXfs são o que as células
     * referenciam em s="..." — a ordem aqui é a das constantes e não pode ser
     * remexida sem trocar os valores delas.
     *
     * O cabeçalho usa o preto e o dourado da interface: quem abre o arquivo
     * reconhece de onde ele saiu.
     */
    private function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="2">'
            . '<numFmt numFmtId="164" formatCode="DD/MM/YYYY"/>'
            . '<numFmt numFmtId="165" formatCode="&quot;R$&quot;\ #,##0.00"/>'
            . '</numFmts>'
            . '<fonts count="4">'
            . '<font><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><color rgb="FFD4AF37"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="14"/><name val="Calibri"/></font>'
            . '</fonts>'
            // O Excel exige exatamente estes dois primeiros fills.
            . '<fills count="3">'
            . '<fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid">'
            . '<fgColor rgb="FF0A0A0B"/><bgColor indexed="64"/></patternFill></fill>'
            . '</fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="6">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="0" fontId="2" fillId="2" borderId="0" xfId="0" applyFont="1"'
            . ' applyFill="1" applyAlignment="1"><alignment vertical="center"/></xf>'
            . '<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }

    private function sheet(int $indice): string
    {
        $linhas = $this->celulas[$indice] ?? [];
        ksort($linhas, SORT_NUMERIC);

        $xml = '';
        foreach ($linhas as $numero => $colunas) {
            ksort($colunas, SORT_NUMERIC);
            $xml .= '<row r="' . $numero . '">';
            foreach ($colunas as $coluna => $celula) {
                $xml .= $this->celulaXml($numero, $coluna, $celula['valor'], $celula['estilo']);
            }
            $xml .= '</row>';
        }

        $filtro = isset($this->filtros[$indice])
            ? '<autoFilter ref="' . self::escapeXml($this->filtros[$indice]) . '"/>'
            : '';

        // A ordem dos elementos é fixada pelo schema (sheetViews, cols,
        // sheetData, autoFilter). Fora dela o Excel recusa o arquivo inteiro.
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . $this->sheetViews($indice)
            . $this->cols($indice)
            . '<sheetData>' . $xml . '</sheetData>'
            . $filtro
            . '</worksheet>';
    }

    /** Painel congelado: o cabeçalho continua visível ao rolar a tabela. */
    private function sheetViews(int $indice): string
    {
        $linha = $this->congeladas[$indice] ?? 0;
        if ($linha < 1) {
            return '';
        }
        return '<sheetViews><sheetView workbookViewId="0">'
            . '<pane ySplit="' . $linha . '" topLeftCell="A' . ($linha + 1) . '"'
            . ' activePane="bottomLeft" state="frozen"/>'
            . '</sheetView></sheetViews>';
    }

    private function cols(int $indice): string
    {
        $larguras = $this->larguras[$indice] ?? [];
        if ($larguras === []) {
            return '';
        }
        $xml = '';
        foreach ($larguras as $i => $largura) {
            $n = $i + 1;
            $xml .= '<col min="' . $n . '" max="' . $n . '" width="'
                . number_format($largura, 2, '.', '') . '" customWidth="1"/>';
        }
        return '<cols>' . $xml . '</cols>';
    }

    private function celulaXml(int $linha, int $coluna, mixed $valor, int $estilo): string
    {
        $ref = self::letraDaColuna($coluna) . $linha;
        $s = $estilo !== self::NORMAL ? ' s="' . $estilo . '"' : '';

        if ($estilo === self::DATA) {
            $serial = self::dataParaSerial((string) $valor);
            return $serial === null
                ? ''
                : '<c r="' . $ref . '"' . $s . '><v>' . $serial . '</v></c>';
        }

        if (is_int($valor) || is_float($valor)) {
            // Sem notação científica nem separador de milhar: o XLSX espera o
            // número no formato canônico.
            $n = is_float($valor) ? rtrim(rtrim(number_format($valor, 6, '.', ''), '0'), '.') : (string) $valor;
            return '<c r="' . $ref . '"' . $s . '><v>' . ($n === '' ? '0' : $n) . '</v></c>';
        }

        return '<c r="' . $ref . '"' . $s . ' t="inlineStr"><is><t xml:space="preserve">'
            . self::escapeXml((string) $valor) . '</t></is></c>';
    }

    // --------------------------------------------------------------- util

    /** 'YYYY-MM-DD' -> serial do Excel (base 30/12/1899). */
    private static function dataParaSerial(string $iso): ?int
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $iso) !== 1) {
            return null;
        }
        $tz = new \DateTimeZone('UTC');
        $data = \DateTimeImmutable::createFromFormat('!Y-m-d', $iso, $tz);
        if ($data === false) {
            return null;
        }
        $base = new \DateTimeImmutable('1899-12-30', $tz);
        return (int) $base->diff($data)->days * ($data < $base ? -1 : 1);
    }

    /** 1 -> A, 27 -> AA */
    public static function letraDaColuna(int $coluna): string
    {
        $letra = '';
        while ($coluna > 0) {
            $resto = ($coluna - 1) % 26;
            $letra = chr(65 + $resto) . $letra;
            $coluna = intdiv($coluna - 1, 26);
        }
        return $letra;
    }

    /** 'AA' -> 27 */
    private static function indiceDaColuna(string $letras): int
    {
        $indice = 0;
        foreach (str_split(strtoupper($letras)) as $ch) {
            $indice = $indice * 26 + (ord($ch) - 64);
        }
        return $indice;
    }

    /**
     * Escapa o texto para XML e remove os bytes de controle que o XML 1.0 não
     * admite — um deles no meio de uma observação deixaria o arquivo ilegível.
     */
    private static function escapeXml(string $v): string
    {
        $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $v) ?? $v;
        return htmlspecialchars($v, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
