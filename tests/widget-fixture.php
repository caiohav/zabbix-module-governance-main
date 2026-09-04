<?php
// Minimal rendering contract of Zabbix 6.0 CWidget, including controls outside main.
class CWidget {
    private $items = [], $title = '', $controls = '';
    public function setTitle($title) { $this->title = $title; return $this; }
    public function setControls($controls) { $this->controls = $controls; return $this; }
    public function addItem($item) { $this->items[] = $item; return $this; }
    public function show() {
        echo '<header class="header-title"><div><h1>' . htmlspecialchars($this->title, ENT_QUOTES, 'UTF-8')
            . '</h1></div><div class="header-controls">' . $this->controls . '</div></header>'
            . '<main>' . implode('', $this->items) . '</main>';
    }
}
