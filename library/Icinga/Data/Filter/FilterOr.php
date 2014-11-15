<?php
// {{{ICINGA_LICENSE_HEADER}}}
// {{{ICINGA_LICENSE_HEADER}}}

namespace Icinga\Data\Filter;

class FilterOr extends FilterChain
{
    protected $operatorName = 'OR';

    protected $operatorSymbol = '|';

    public function matches($row)
    {
        foreach ($this->filters as $filter) {
            if ($filter->matches($row)) {
                return true;
            }
        }
        return false;
    }

    public function setOperatorName($name)
    {
        if ($this->count() > 1 && $name === 'NOT') {
            return Filter::not(clone $this);
        }
        return parent::setOperatorName($name);
    }

    public function andFilter(Filter $filter)
    {
        return Filter::matchAll($this, $filter);
    }

    public function orFilter(Filter $filter)
    {
        return $this->addFilter($filter);
    }
}
