<?php

namespace Korbytes\AiGateway;

enum AiFinishReason: string
{
    case Stop = 'stop';
    case Length = 'length';
    case ContentFilter = 'content_filter';
    case Other = 'other';
}
