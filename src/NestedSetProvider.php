<?php

namespace Lazychaser\DataImportTools;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Kalnoy\Nestedset\NodeTrait;

abstract class NestedSetProvider extends BaseProvider
{
    /**
     * @var Collection
     */
    protected $preloaded;

    public function flush()
    {
        $this->preloaded = null;
    }

    /**
     * @param array $key
     *
     * @return bool|Model
     */
    public function find($key)
    {
        if (empty($key)) {
            return false;
        }

        $data = $this->getNodes();

        foreach ($key as $entry) {
            if ($this->slugKey) {
                $entry = str_slug($entry);
            }

            if (empty($entry) || ! $data->has($entry)) {
                return false;
            }

            $data = $data->get($entry);
        }

        return $data->get(0);
    }

    /**
     * @param array $keys
     * @param int $mode
     *
     * @return Model
     */
    public function fetch($keys, $mode = self::READ_CREATE)
    {
        if (empty($keys)) {
            return null;
        }

        $data = $this->getNodes();

        foreach ($keys as $key) {
            $lastNodeIsNew = false;

            $originalKey = $key;

            if ($this->slugKey) {
                $key = Str::slug($key);
            }

            if ( ! $data->has($key)) {
                if ( ! $this->createAllowed($mode)) {
                    return null;
                }

                $node = $this->create($originalKey, $data->get(0));

                $node->save();

                $data->put($key, $this->mapNode($node));

                $lastNodeIsNew = true;
            }

            $data = $data->get($key);
        }

        /** @noinspection PhpUndefinedVariableInspection */
        return $this->updateAllowed($mode) || $lastNodeIsNew ? $data->get(0) : null;
    }

    /**
     * @param array|EloquentCollection|string $keys
     *
     * @return $this
     */
    public function preload($keys)
    {
        return $this;
    }

    /**
     * @param string $key
     * @param null|Model $parent
     *
     * @return Model
     */
    protected function create($key, $parent = null)
    {
        /** @var Model $model */
        $model = parent::create($key);

        if ($parent) {
            $model->appendToNode($parent);
        }

        $model->setRelation('children', new EloquentCollection);

        return $model;
    }

    /**
     * @param \Illuminate\Support\Collection $items
     *
     * @return \Illuminate\Support\Collection
     */
    protected function mapNodes(Collection $items)
    {
        return $items->keyBy($this->primaryKey())->map(function ($node) {
            return $this->mapNode($node);
        });
    }

    /**
     * @param Model $node
     *
     * @return \Illuminate\Support\Collection
     */
    protected function mapNode(Model $node)
    {
        $data = $node->children
            ? $this->mapNodes($node->children)
            : new Collection;

        $data->put(0, $node);

        return $data;
    }

    /**
     * @param $value
     *
     * @return array
     */
    public function normalizeKey($value)
    {
        if (empty($value)) {
            return [];
        }

        if (is_string($value)) {
            $value = explode('\\', str_replace('/', '\\', $value));
        }

        return array_map('trim', $value);
    }

    /**
     * @return Collection
     */
    protected function getNodes()
    {
        if (is_null($this->preloaded)) {
            $tree = $this->newQuery()
                         ->get($this->processColumns($this->columns))
                         ->toTree();

            $this->preloaded = $this->mapNodes($tree);
        }

        return $this->preloaded;
    }

    /**
     * @param array $columns
     *
     * @return array
     */
    protected function processColumns(array $columns)
    {
        if ($columns == [ '*' ]) return $columns;

        /** @var Model $model */
        $model = $this->newEmptyModel();

        return array_unique(array_merge($columns, [

            $this->primaryKey(),
            $model->getKeyName(),
            $model->getParentIdName(),
            $model->getLftName(),
            $model->getRgtName(),
        ]));
    }

}