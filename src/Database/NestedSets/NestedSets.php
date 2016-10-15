<?php

namespace MaxiSoft\Database\NestedSets;

use MaxiSoft\Database\Database;

class NestedSets extends Database
{
    protected $table;
    protected $DBH;

    // конструктор класса
    public function __construct($table)
    {
        $this->table = $table;
        $this->DBH = parent::init();
    }

    // Добавить узел
    public function addNode($new_node, $position = 'bottom')
    {
        // получаем родительский узел
        $node = $this->getNode($new_node['parent_id']);

        // проверка наличия родительского узла (заложена возможность много корневых деревьев в одной таблице)
        if (!$node) {

            $new_node['parent_id'] = 1;
            $level = 1;

            // порядок добавления нового узла
            if ($position == 'top') {
                // в начало ветки
                $key = 1;
            } else {
                // в конец ветки

                // получаем максимальный правый ключ
                $res = parent::init()
                    ->select($this->table)
                    ->fields(array('MAX(NSRight) as max_key'))
                    ->run()
                    ->fetchField('max_key');
                $key = (int) $res + 1;
            }
        } else {
            // получаем ключи для нового узла
            $key = $position == 'top' ? $node['NSLeft'] + 1 : $node['NSRight'];
            $level = $node['NSLevel'] + 1;
        }

        // обновляем ключи (левый, правый) для всего дерева
        $sql_update = 'UPDATE ' . $this->table .
            ' SET
								NSRight = NSRight + 2,
								NSLeft = IF (NSLeft > ' . $key . ', NSLeft + 2, NSLeft)
							WHERE
								NSRight >= ' . $key;
        parent::init()->exec($sql_update);

        // считаем что новые узлы добавляются в конец ветки дерева
        if ($position == 'bottom') {

            // получаем максимальный ключ сортировки в ветке дерева
            $res = parent::init()
                ->select($this->table)
                ->where('parent_id', '=', $new_node['parent_id'])
                ->orderby('ordering', 'DESC')
                ->limit(0, 1)
                ->run()
                ->fetchAssoc();

            // увеличиваем ключи сортировки
            $order = $res ? $res['ordering'] + 1 : 1;

            // обновление ключей сортировки нижестоящих узлов в ветке дерева
            $sql_update = "UPDATE " . $this->table .
                " SET ordering = ordering + 1" .
                " WHERE parent_id = " . $new_node['parent_id'] .
                " AND ordering >=" . $order;

            parent::init()->exec($sql_update);
        }

        // инициализация значении ключей и уровня для нового узла
        $new_node['NSLeft'] = $key;
        $new_node['NSRight'] = $key + 1;
        $new_node['NSLevel'] = $level;
        $new_node['ordering'] = $order;

        // создание нового узла
        parent::init()
            ->insert($this->table)
            ->fields($new_node)
            ->run();

        // отдам результат с данными созданного узла
        return $this->getNode(parent::init()->lastInsertId());
    }

    // Удалить узел
    public function deleteNode($id)
    {
        // получаем данные узла
        $node = $this->getNode($id);

        // удаляем узел из дерева
        parent::init()
            ->delete($this->table)
            ->where('NSLeft', '>=', $node['NSLeft'])
            ->andWhere('NSRight', '<=', $node['NSRight'])
            ->run();

        // обновляем левые и правые ключи дерева
        $sql_update = 'UPDATE ' . $this->table . '
							SET
								NSLeft = IF(NSLeft > ' . $node['NSLeft'] . ', NSLeft - (' . $node['NSRight'] . ' - ' . $node['NSLeft'] . ' + 1), NSLeft),
								NSRight = NSRight - (' . $node['NSRight'] . ' - ' . $node['NSLeft'] . ' + 1)
							WHERE
								NSRight > ' . $node['NSRight'];

        parent::init()->exec($sql_update);

        return true;
    }

    // простое перемещение в другой узел
    public function moveNode($id, $id_to, $order = -1)
    {
        // перенос в корень запрещен
        if (!$id_to) {
            return false;
        }

        // получаем текущий узел
        $node = $this->getNode($id);

        // получаем родителя текущего узла
        $node_parent = $this->getParentNode($id);

        // получаем нового родителя узла
        $node_to = $this->getNode($id_to);

        // перенос в текущей ветке дерева, выполнение функции сортировки
        if ($node_parent['id'] == $node_to['id']) {

            // системная пометка узлов ветки для последующей работы с ними
            parent::init()
                ->update($this->table)
                ->fields(array('NSIgnore' => 1))
                ->where('NSLeft', '>=', $node['NSLeft'])
                ->andWhere('NSRight', '<=', $node['NSRight'])
                ->run();

            // если не указан order добавляем узел в конец ветки
            if ($order == -1) {
                $res = parent::init()
                    ->select($this->table)
                    ->where('parent_id', '=', $node['parent_id'])
                    ->orderby('ordering', 'DESC')
                    ->limit(0, 1)
                    ->run()
                    ->fetchAssoc();
                // получаем макcимальное значение сортировки в ветке, если в ветке нет узлов считаем order = 1
                $order = $res['ordering'] ? $res['ordering'] + 1 : 1;
            }

            // выполняем декремент индекса сортировки нижестоящих узлов
            $sql_update = " UPDATE
									{$this->table}
                        		SET
                        			ordering = ordering + 1
                        		WHERE
                        			parent_id = {$node['parent_id']} AND
                        			ordering >= {$order}";

            parent::init()
                ->prepare($sql_update)
                ->execute();

            // получаем вышестоящий индекса сортировки в дереве от выбранного узла
            $res = parent::init()
                ->select($this->table)
                ->where('parent_id', '=', $node['parent_id'])
                ->andWhere('ordering', '<=', $order)
                ->orderby('ordering', 'DESC')
                ->limit(0, 1)
                ->run()
                ->fetchAssoc();

            // рассчитываем левый ключ дерева
            $left = $res['NSRight'] ? $res['NSRight'] + 1 : $node_parent['NSLeft'] + 1;

            // расчет смещения ключей дерева
            $offset = $node['NSRight'] - $node['NSLeft'] + 1;

            // обновление левых ключей дерева
            if ($left < $node['NSLeft']) {

                // обновление левых ключей при перемещении узла вверх по дереву
                $sql_update = " UPDATE
                    					{$this->table}
                    				SET
                    					NSLeft = NSLeft + ({$offset})
                            		WHERE
                            			NSLeft >= {$left} AND
                            			NSLeft <= {$node['NSLeft']} AND
                            			NSIgnore = 0";

            } else {

                // обновление левых ключей при перемещении узла вниз по дереву
                $sql_update = " UPDATE
                    					{$this->TableName}
                    				SET
                    					NSLeft = NSLeft - {$offset}
                            		WHERE
                            			NSLeft <= {$left} AND
                            			NSLeft >= {$node['NSLeft']} AND
                            			NSIgnore = 0";
            }

            parent::init()
                ->prepare($sql_update)
                ->execute();

            // обновление правых ключей дерева
            if ($left < $node['NSLeft']) {

                // обновление правых ключей при перемещении узла вверх по дереву
                $sql_update = " UPDATE
                    					{$this->table}
                            		SET
                            			NSRight = NSRight + ({$offset})
                            		WHERE
                            			NSRight >= {$left} AND
                            			NSRight <= {$node['NSRight']} AND
                            			NSIgnore = 0";
            } else {

                // обновление правых ключей при перемещении узла вверх по дереву
                $sql_update = " UPDATE
                    					{$this->table}
                    				SET
                    					NSRight = NSRight - {$offset}
                    				WHERE
										NSRight < {$left} AND
										NSRight >= {$node['NSRight']}AND
                            			NSIgnore = 0";
            }

            parent::init()
                ->prepare($sql_update)
                ->execute();

            // получаем смещение ключа уровня
            $level_difference = $node_parent['NSLevel'] - $node['NSLevel'] + 1;

            // получаем значения полного смещения ключей
            $new_offset = $node['NSLeft'] - $left;

            // если перемещаем вверх увеличиваем значение смещения на смещение ключей узла
            if ($left > $node['NSLeft']) {
                $new_offset += $offset;
            }

            // полное обновление ключей дерева
            $sql_update = " UPDATE
									{$this->table}
                        		SET
                        			NSLeft = NSLeft - ({$new_offset}),
                        			NSRight = NSRight - ({$new_offset}),
                        			NSLevel = NSLevel + {$level_difference}
                        		WHERE
									NSLeft >= {$node['NSLeft']} AND
									NSRight <= {$node['NSRight']} AND
                            		NSIgnore = 1";

            parent::init()
                ->prepare($sql_update)
                ->execute();

            // снятие флага блокировки с узлов дерева
            $sql_update = " UPDATE
			    					{$this->table}
			    				SET
			    					NSIgnore = 0
			    				WHERE
									NSLeft >= ({$node['NSLeft']} - {$new_offset}) AND
									NSRight <= ({$node['NSRight']} - {$new_offset}) AND
									NSIgnore = 1";
            parent::init()
                ->prepare($sql_update)
                ->execute();

            // обновление данных текущего узла
            parent::init()
                ->update($this->table)
                ->fields(array('ordering' => $order))
                ->where('id', '=', $node['id'])
                ->run();
        }

        // перенос в ветку дерева, с выполнение функции сортировки
        if ($node_parent['id'] != $node_to['id']) {

            // если не указан индекс сортировки то считаем что узел добавлен в конец ветки
            if ($order == -1) {
                $res = parent::init()
                    ->select($this->table)
                    ->where('parent_id', '=', $node['parent_id'])
                    ->orderby('ordering', 'DESC')
                    ->limit(0, 1)
                    ->run()
                    ->fetchAssoc();

                // получаем макcимальное значение сортировки в ветке, если в ветке нет узлов считаем order = 1
                $order = $res['ordering'] ? $res['ordering'] + 1 : 1;
            }

            // выполняем декремент индекса сортировки нижестоящих узлов
            $sql_update = " UPDATE
									{$this->table}
	                        	SET
	                        		ordering = ordering + 1
	                        	WHERE
	                      			parent_id = {$node['parent_id']} AND
	                        		ordering >= {$order}";

            parent::init()
                ->prepare($sql_update)
                ->execute();

            // получаем данные для перемещаемого узла
            $NSLeft = $node['NSLeft'];
            $NSRight = $node['NSRight'];
            $NSLevel = $node['NSLevel'];

            // получаем уровень parent узла
            $NSLevel_up = $node_to['NSLevel'];
            $STH = $this->DBH->prepare('SELECT (NSRight - 1) AS NSRight FROM ' . $this->table . ' WHERE id = ' . $id_to);
            $STH->execute();
            $res = $STH->fetch(PDO::FETCH_ASSOC);

            // получаем правый ключ parent узла
            $NSRight_near = $res['NSRight'];

            // получаем смещения узлов для переноса
            $skew_NSLevel = $NSLevel_up - $NSLevel + 1;
            $skew_tree = $NSRight - $NSLeft + 1;

            // получаем список всех Id узлов которые будут перемещаться
            $STH = $this->DBH->prepare('SELECT id FROM ' . $this->table . ' WHERE NSLeft >= ' . $NSLeft . ' AND NSRight <= ' . $NSRight);
            $STH->execute();
            $id_edit = array();
            while ($row = $STH->fetch(PDO::FETCH_ASSOC)) {
                $id_edit[] = $row['id'];
            }

            $id_edit = implode(', ', $id_edit);

            // выполняем обновление ключей дерева для перемещения
            if ($NSRight_near < $NSRight) {

                // вышестоящие узлы
                $skew_edit = $NSRight_near - $NSLeft + 1;

                // обновляем правые ключи
                $sql[0] = '
						UPDATE ' . $this->table . '
						SET NSRight = NSRight + ' . $skew_tree . '
						WHERE
							NSRight < ' . $NSLeft . ' AND
							NSRight > ' . $NSRight_near;

                // обновляем левые ключи
                $sql[1] = '
						UPDATE ' . $this->table . '
						SET NSLeft = NSLeft + ' . $skew_tree . '
						WHERE
							NSLeft < ' . $NSLeft . ' AND
							NSLeft > ' . $NSRight_near;

                // обновляем ключи дочерных узлов
                $sql[2] = '
						UPDATE ' . $this->table . '
						SET NSLeft = NSLeft + ' . $skew_edit . ',
							NSRight = NSRight + ' . $skew_edit . ',
							NSLevel = NSLevel + ' . $skew_NSLevel . '
						WHERE id IN (' . $id_edit . ')';

            } else {

                //нижестоящие узлы
                $skew_edit = $NSRight_near - $NSLeft + 1 - $skew_tree;

                // обновляем правые ключи
                $sql[0] = '
						UPDATE ' . $this->table . '
						SET NSRight = NSRight - ' . $skew_tree . '
						WHERE
							NSRight > ' . $NSRight . ' AND
							NSRight <= ' . $NSRight_near;

                // обновляем левые ключи
                $sql[1] = '
						UPDATE ' . $this->table . '
						SET NSLeft = NSLeft - ' . $skew_tree . '
						WHERE
							NSLeft > ' . $NSRight . ' AND
							NSLeft <= ' . $NSRight_near;

                // обновляем ключи дочерных узлов
                $sql[2] = '
						UPDATE ' . $this->table . '
						SET NSLeft = NSLeft + ' . $skew_edit . ',
							NSRight = NSRight + ' . $skew_edit . ',
							NSLevel = NSLevel + ' . $skew_NSLevel . '
						WHERE id IN (' . $id_edit . ')';
            }

            // обновляем данные текущего узла
            $sql[3] = "UPDATE {$this->table} SET parent_id={$id_to}, ordering={$order} WHERE id={$id} LIMIT 1";

            foreach ($sql as $query) {
                $STH = $this->DBH->prepare($query);
                $STH->execute();
            }

        }

        return true;
    }

    // Получить корневой элемент
    public function getRootNode()
    {
        // получаем информация о корневом узле
        return Database::init()
            ->select($this->table)
            ->where('parent_id', '=', '0')
            ->run()
            ->fetchAssoc();
    }

    // Получить узел
    public function getNode($id)
    {
        // получаем информацию о узле по Id
        return parent::init()
            ->select($this->table)
            ->where('id', '=', $id)
            ->run()
            ->fetchAssoc();
    }

    public function getNodeByLink($seo_link)
    {
        // получаем информацию о узле по seo_link
        return parent::init()
            ->select($this->table)
            ->where('seo_link', '=', $seo_link)
            ->run()
            ->fetchAssoc();
    }

    // Дерево
    public function getTree($parent_node = true)
    {
        // получаем дерево
        $res = Database::init()
            ->select($this->table)
            ->orderBy('NSLeft');
        // не выводим корневой узел
        if (!$parent_node) {
            $res->where('id', '!=', 1);
        }

        return $res->run()->fetchAllAssoc();
    }

    // Подчиненная ветка
    public function getChildBranch($id, $parent_node = false)
    {
        // получаем информацию о узле
        $node = $this->getNode($id);

        // получаем данные по подчиненным узлам веток
        parent::init()
            ->select($this->table)
            ->where('NSLeft', '>=', $node['NSLeft'])
            ->andWhere('NSRight', '<=', $node['NSRight'])
            ->orderBy('NSLeft');

        // режим вывода текущего узла
        if (!$parent_node) {
            parent::init()->andWhere('id', '!=', $id);
        }

        return parent::init()
            ->run()
            ->fetchAllAssoc();
    }

    // Подчиненные узлы
    public function getChild($id, $parent_node = false)
    {
        // получаем данные о узле
        $node = $this->getNode($id);

        // получаем данные по подчиненным узлам в текущей ветке
        parent::init()
            ->select($this->table)
            ->where('NSLeft', '>=', $node['NSLeft'])
            ->andWhere('NSRight', '<=', $node['NSRight'])
            ->andWhere('NSLevel', '=', $node['NSLevel'] + 1);

        // режим вывода текущего узла
        if (!$parent_node) {
            parent::init()->andWhere('id', '!=', $id);
        }

        return parent::init()
            ->orderBy('NSLeft')
            ->run()
            ->fetchAllAssoc();
    }

    // Родительская ветка
    public function getParentBranch($id, $parent_node = false, $root_node = false)
    {
        // получаем информацию о узле
        $node = $this->getNode($id);

        // получаем информацию о узлах родительской ветки
        parent::init()
            ->select($this->table)
            ->where('NSLeft', '<=', $node['NSLeft'])
            ->andwhere('NSRight', '>=', $node['NSRight'])
            ->orderBy('NSLeft');

        // режим вывода текущего узла
        if (!$parent_node) {
            parent::init()->andWhere('id', '!=', $id);
        }

        // режим вывода корневого узла
        if (!$root_node) {
            parent::init()->andWhere('parent_id', '>', 0);
        }

        return parent::init()->run()
            ->fetchAllAssoc();
    }

    // Родительский узел
    public function getParentNode($id)
    {
        // получаем данные о узле
        $node = $this->getNode($id);

        // получаем данные о родительском узле
        return parent::init()
            ->select($this->table)
            ->where('NSLeft', '<=', $node['NSLeft'])
            ->andWhere('NSRight', '>=', $node['NSRight'])
            ->andWhere('NSLevel', '=', $node['NSLevel'] - 1)
            ->orderBy('NSLevel')
            ->run()
            ->fetchAssoc();
    }

    // Ветка
    public function getBranch($id)
    {
        //получаем данные о узле
        $node = $this->getNode($id);

        // получаем данные о ветке
        return parent::init()
            ->select($this->table)
            ->where('NSLeft', '>', $node['NSLeft'])
            ->andWhere('NSRight', '<', $node['NSRight'])
            ->orderBy('NSLeft')
            ->run()
            ->fetchAllAssoc();
    }

    // Рендеринг списка UL > LI формата
    public function renderList($cats)
    {
        // инициализация начальных данных
        $output = '';
        $prev_level = 0;
        $levels['max'] = max(array_map(function ($n) {
            return $n['NSLevel'];
        }, $cats));
        $levels['min'] = min(array_map(function ($n) {
            return $n['NSLevel'];
        }, $cats));

        if ($counts = count($cats)) {
            $i = 0;
            $open_ul = 0;
            foreach ($cats as $item) {
                $i++;

                $item_level = $item['NSLevel'] - $levels['min'];

                if ($i != 1 && $prev_level == $item_level) {
                    $output .= '</li>';
                }

                if ($item_level < $prev_level) {
                    $difference = $prev_level - $item_level;
                    $output .= $this->renderTags('</ul></li>', $difference);
                    $open_ul = $open_ul - $difference;
                }

                if ($item_level > $prev_level) {
                    $output .= $this->renderTags('<ul>');
                    ++$open_ul;
                }

                $output .= $this->renderItem($item);

                if ($counts == $i) {
                    if ($open_ul > 1) {
                        $output .= $this->renderTags('</ul></li>', $open_ul - 1);
                        $output .= $this->renderTags('</ul>');
                    } else {
                        $output .= $this->renderTags('</li></ul>');
                    }

                }
                $prev_level = $item_level;
            }
        }

        return $output;
    }

    // Вывод елемента списка
    protected function renderItem($item)
    {
        return '<li>' . $item['id'] . ' (' . $item['NSLevel'] . ')';
    }

    // Вывод закрывающих тегов списка
    protected function renderTags($tag, $amount = 1)
    {
        $output = '';
        if ($amount > 0) {
            for ($i = 1; $i <= $amount; $i++) {
                $output .= $tag;
            }
        }

        return $output;
    }

}
